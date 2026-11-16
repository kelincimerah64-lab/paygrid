<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Models\ReconciliationRun;
use App\Services\Gateway\HilogateClient;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Monthly full-history reconciliation for one merchant's withdrawals.
 * SyncMerchantWithdrawals (the regular 5-minute job) stops paging as soon as
 * it hits a page where every row is already known locally - fast for the
 * normal case, but it means a gap (older unsynced withdrawals sitting behind
 * newer synced ones, e.g. from a past sync hiccup) is never discovered. This
 * job walks every page until several consecutive pages are entirely older
 * than the target month's start, regardless of what's already known.
 */
class ReconcileMerchantMonthWithdrawals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $merchantId, public readonly string $month) {}

    public function handle(HilogateClient $client): void
    {
        $merchant = Merchant::query()->find($this->merchantId);
        if (! $merchant || ! in_array($merchant->gateway, ['hilogate', 'magnetopay'], true) || ! $merchant->merchant_id) {
            return;
        }

        $run = ReconciliationRun::query()->firstOrCreate(
            ['merchant_id' => $merchant->id, 'type' => 'withdrawal', 'month' => $this->month],
            ['status' => 'running', 'started_at' => now()]
        );

        if ($run->status === 'completed') {
            return;
        }

        $monthStart = CarbonImmutable::createFromFormat('Y-m', $this->month, 'Asia/Jakarta')->startOfMonth();
        $monthEnd = CarbonImmutable::createFromFormat('Y-m', $this->month, 'Asia/Jakarta')->endOfMonth();

        $pageSize = 100;
        $pagesPerRun = max(1, (int) config('paygrid.gateway_sync.reconciliation_pages_per_run', 10));
        $stopAfterOlderPages = max(1, (int) config('paygrid.gateway_sync.reconciliation_stop_after_pages', 5));

        $meta = $run->meta ?? ['next_page' => 1, 'done' => false, 'older_streak' => 0];
        $page = $meta['next_page'];
        $olderStreak = $meta['older_streak'];

        $seen = $run->records_seen ?? 0;
        $added = $run->records_added ?? 0;
        $updated = $run->records_updated ?? 0;
        $pagesWalked = $run->pages_walked ?? 0;
        $pagesThisRun = 0;

        while ($pagesThisRun < $pagesPerRun) {
            $rows = $client->pullWithdrawals($merchant, ['page' => $page, 'page_size' => $pageSize]);
            $pagesWalked++;
            $pagesThisRun++;

            if ($rows === []) {
                $meta['done'] = true;
                break;
            }

            $pageHasInMonthRow = false;
            foreach ($rows as $row) {
                if (empty($row['id'])) {
                    continue;
                }

                $seen++;
                $createdAt = ! empty($row['created_at']) ? Carbon::createFromTimestampMs((int) $row['created_at'])->timezone('Asia/Jakarta')->toDateString() : null;
                if ($createdAt === null || $createdAt < $monthStart->toDateString() || $createdAt > $monthEnd->toDateString()) {
                    continue;
                }

                $pageHasInMonthRow = true;
                $existing = MerchantWithdrawal::query()->where('gateway_withdrawal_id', $row['id'])->first();

                MerchantWithdrawal::query()->updateOrCreate(
                    ['gateway_withdrawal_id' => $row['id']],
                    [
                        'merchant_id' => $merchant->id,
                        'gateway' => $merchant->gateway,
                        'ref_id' => $row['ref_id'] ?? null,
                        'status' => $row['status'] ?? 'UNKNOWN',
                        'amount' => (int) ($row['amount'] ?? 0),
                        'net_amount' => (int) ($row['net_amount'] ?? 0),
                        'fee' => (int) ($row['total_fee'] ?? $row['fee'] ?? 0),
                        'bank_code' => $row['bank_code'] ?? null,
                        'bank_name' => $row['bank_name'] ?? null,
                        'account_number' => $row['account_number'] ?? null,
                        'account_name' => $row['account_name'] ?? null,
                        'gateway_created_at' => ! empty($row['created_at']) ? Carbon::createFromTimestampMs((int) $row['created_at']) : null,
                        'gateway_completed_at' => ! empty($row['completed_at']) ? Carbon::createFromTimestampMs((int) $row['completed_at']) : null,
                        'gateway_payload' => $row,
                        'synced_at' => now(),
                    ],
                );

                $existing ? $updated++ : $added++;
            }

            $olderStreak = $pageHasInMonthRow ? 0 : $olderStreak + 1;
            $page++;

            // Persisted after every page (not just at the end of this run's
            // budget) so the Superadmin progress table reflects real work as
            // it happens, rather than sitting at 0 for however long a single
            // merchant's walk takes.
            $run->forceFill([
                'pages_walked' => $pagesWalked,
                'records_seen' => $seen,
                'records_added' => $added,
                'records_updated' => $updated,
            ])->save();

            if ($olderStreak >= $stopAfterOlderPages) {
                $meta['done'] = true;
                break;
            }
        }

        $meta['next_page'] = $page;
        $meta['older_streak'] = $olderStreak;
        $done = $meta['done'] ?? false;

        $run->forceFill([
            'status' => $done ? 'completed' : 'running',
            'pages_walked' => $pagesWalked,
            'records_seen' => $seen,
            'records_added' => $added,
            'records_updated' => $updated,
            'meta' => $meta,
            'completed_at' => $done ? now() : null,
        ])->save();

        if (! $done) {
            self::dispatch($this->merchantId, $this->month)->onQueue('backfill');
        }
    }

    public function failed(\Throwable $exception): void
    {
        ReconciliationRun::query()
            ->where(['merchant_id' => $this->merchantId, 'type' => 'withdrawal', 'month' => $this->month])
            ->update(['status' => 'failed', 'last_error' => substr($exception->getMessage(), 0, 500)]);
    }
}
