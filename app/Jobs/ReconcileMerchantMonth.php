<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\ReconciliationRun;
use App\Services\Gateway\GatewayManager;
use App\Services\TransactionIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Monthly full-history reconciliation for one merchant's collection (topup)
 * transactions. Unlike BackfillMerchantTransactions (today-only, abandons an
 * incomplete walk at midnight), this always targets a past, closed month and
 * walks every page until it's seen several consecutive pages entirely older
 * than the month's start - not "first already-known row", since that
 * shortcut is exactly how SyncMerchantWithdrawals-style jobs can miss a gap
 * (older unsynced rows sitting behind newer synced ones).
 */
class ReconcileMerchantMonth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $merchantId, public readonly string $month) {}

    public function handle(GatewayManager $gateways, TransactionIngestionService $ingestion): void
    {
        $merchant = Merchant::query()->find($this->merchantId);
        if (! $merchant || $merchant->approval_status !== 'approved' || ! $merchant->merchant_id) {
            return;
        }

        $run = ReconciliationRun::query()->firstOrCreate(
            ['merchant_id' => $merchant->id, 'type' => 'collection', 'month' => $this->month],
            ['status' => 'running', 'started_at' => now()]
        );

        if ($run->status === 'completed') {
            return;
        }

        $monthStart = CarbonImmutable::createFromFormat('Y-m', $this->month, 'Asia/Jakarta')->startOfMonth();
        $monthEnd = CarbonImmutable::createFromFormat('Y-m', $this->month, 'Asia/Jakarta')->endOfMonth();

        $pageSize = (int) config('paygrid.gateway_sync.page_size', 100);
        $pagesPerRun = max(1, (int) config('paygrid.gateway_sync.reconciliation_pages_per_run', 10));
        $stopAfterOlderPages = max(1, (int) config('paygrid.gateway_sync.reconciliation_stop_after_pages', 5));

        $client = $gateways->for($merchant);
        $modes = $this->modesToTry($merchant);
        $meta = $run->meta ?? [];

        $seen = $run->records_seen ?? 0;
        $added = $run->records_added ?? 0;
        $updated = $run->records_updated ?? 0;
        $pagesWalked = $run->pages_walked ?? 0;

        foreach ($modes as $mode) {
            $key = $mode ?? 'default';
            $state = $meta[$key] ?? ['next_page' => 1, 'done' => false, 'older_streak' => 0];
            if ($state['done']) {
                continue;
            }

            $page = $state['next_page'];
            $olderStreak = $state['older_streak'];
            $pagesThisRun = 0;

            while ($pagesThisRun < $pagesPerRun) {
                $rows = $client->pullTransactions($merchant, [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'pull_mode' => $mode,
                ]);

                $pagesWalked++;
                $pagesThisRun++;

                if ($rows === []) {
                    $state['done'] = true;
                    break;
                }

                $pageHasInMonthRow = false;
                foreach ($rows as $payload) {
                    $date = $this->payloadDate($payload);
                    $seen++;

                    if ($date === null || $date < $monthStart->toDateString() || $date > $monthEnd->toDateString()) {
                        continue;
                    }

                    $pageHasInMonthRow = true;
                    $amount = (int) preg_replace('/\D+/', '', (string) ($payload['amount'] ?? 0));
                    if ((float) ($payload['amount'] ?? 0) <= 0 || $amount <= 0) {
                        continue;
                    }

                    $request = $ingestion->ingestForMerchant($merchant, $payload, $merchant->gateway, $merchant->gateway.'_reconcile', true);
                    $request->wasRecentlyCreated ? $added++ : $updated++;
                }

                $olderStreak = $pageHasInMonthRow ? 0 : $olderStreak + 1;
                $page++;

                // Persisted after every page (not just at the end of this
                // run's budget) so the Superadmin progress table reflects
                // real work as it happens, rather than sitting at 0 for
                // however long a single merchant's walk takes.
                $run->forceFill([
                    'pages_walked' => $pagesWalked,
                    'records_seen' => $seen,
                    'records_added' => $added,
                    'records_updated' => $updated,
                ])->save();

                if ($olderStreak >= $stopAfterOlderPages) {
                    $state['done'] = true;
                    break;
                }
            }

            $state['next_page'] = $page;
            $state['older_streak'] = $olderStreak;
            $meta[$key] = $state;
        }

        $allDone = collect($meta)->count() === count($modes) && collect($meta)->every(fn ($m) => $m['done']);

        $run->forceFill([
            'status' => $allDone ? 'completed' : 'running',
            'pages_walked' => $pagesWalked,
            'records_seen' => $seen,
            'records_added' => $added,
            'records_updated' => $updated,
            'meta' => $meta,
            'completed_at' => $allDone ? now() : null,
        ])->save();

        if (! $allDone) {
            self::dispatch($this->merchantId, $this->month)->onQueue('backfill');
        }
    }

    public function failed(\Throwable $exception): void
    {
        ReconciliationRun::query()
            ->where(['merchant_id' => $this->merchantId, 'type' => 'collection', 'month' => $this->month])
            ->update(['status' => 'failed', 'last_error' => substr($exception->getMessage(), 0, 500)]);
    }

    private function modesToTry(Merchant $merchant): array
    {
        if (! in_array($merchant->gateway, ['hilogate', 'magnetopay'], true)) {
            return [null];
        }

        return $merchant->merchant_type === 'script' ? ['transactions', 'qris'] : ['qris', 'transactions'];
    }

    private function payloadDate(array $payload): ?string
    {
        $value = $payload['paid_at'] ?? $payload['paidAt'] ?? $payload['created_at'] ?? $payload['createdAt'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;

            return CarbonImmutable::createFromTimestampMs($number > 9999999999 ? $number : $number * 1000, 'Asia/Jakarta')->toDateString();
        }

        return CarbonImmutable::parse((string) $value, 'Asia/Jakarta')->toDateString();
    }
}
