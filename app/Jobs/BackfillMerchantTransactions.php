<?php

namespace App\Jobs;

use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Models\SyncCursor;
use App\Services\Gateway\GatewayManager;
use App\Services\Gateway\GatewayClientInterface;
use App\Services\GatewaySyncDispatcher;
use App\Services\MetricRollupService;
use App\Services\TransactionIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BackfillMerchantTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    public function __construct(public readonly int $merchantId, public readonly ?string $date = null) {}

    public function backoff(): array
    {
        return [15, 45, 90];
    }

    public function handle(GatewayManager $gateways, TransactionIngestionService $ingestion, MetricRollupService $rollups, GatewaySyncDispatcher $dispatcher): void
    {
        $startedAt = now();
        $merchant = Merchant::query()->find($this->merchantId);
        $date = $this->date ?: now('Asia/Jakarta')->toDateString();
        $pageSize = (int) config('paygrid.gateway_sync.page_size', 100);
        $livePages = max(1, (int) config('paygrid.gateway_sync.max_pages', 20));
        $pagesPerRun = max(1, (int) config('paygrid.gateway_sync.backfill_pages_per_run', 10));
        $cursorType = 'transaction_backfill_today';

        try {
            if (! $merchant || $merchant->approval_status !== 'approved' || ! $merchant->merchant_id) {
                return;
            }

            if ($date !== now('Asia/Jakarta')->toDateString()) {
                $this->logIfAbandonedIncomplete($merchant, $date, $cursorType);

                return;
            }

            $cursor = SyncCursor::query()->firstOrNew([
                'merchant_id' => $merchant->id,
                'gateway' => $merchant->gateway,
                'cursor_type' => $cursorType,
            ]);
            $meta = is_array($cursor->meta) ? $cursor->meta : [];
            $sameDate = ($meta['date'] ?? null) === $date;
            $modes = $this->modesToTry($merchant);
            $modeProgress = [];
            foreach ($modes as $mode) {
                $key = $mode ?? 'default';
                $stored = $sameDate ? ($meta['mode_progress'][$key] ?? null) : null;
                $modeProgress[$key] = [
                    'next_page' => max($livePages + 1, (int) ($stored['next_page'] ?? ($livePages + 1))),
                    'done' => (bool) ($stored['done'] ?? false),
                ];
            }

            $client = $gateways->for($merchant);
            $total = 0;
            $skipped = 0;
            $pages = 0;
            $todayRows = 0;

            foreach ($modes as $mode) {
                $key = $mode ?? 'default';
                if ($modeProgress[$key]['done']) {
                    continue;
                }

                $page = $modeProgress[$key]['next_page'];
                $pagesForMode = 0;

                while ($pagesForMode < $pagesPerRun) {
                    $rows = $this->pullTransactions($client, $merchant, [
                        'page' => $page,
                        'page_size' => $pageSize,
                        'pull_mode' => $mode,
                    ]);

                    if ($rows === []) {
                        $modeProgress[$key]['done'] = true;
                        break;
                    }

                    $pageHasToday = false;
                    foreach ($rows as $payload) {
                        $submittedAt = $this->payloadDate($payload);
                        if ($submittedAt !== $date) {
                            continue;
                        }

                        $pageHasToday = true;
                        $todayRows++;
                        $amount = (int) preg_replace('/\D+/', '', (string) ($payload['amount'] ?? 0));
                        if ((float) ($payload['amount'] ?? 0) <= 0 || $amount <= 0) {
                            $skipped++;
                            continue;
                        }

                        $ingestion->ingestForMerchant($merchant, $payload, $merchant->gateway, $merchant->gateway.'_backfill_today', true);
                        $total++;
                    }

                    $pagesForMode++;
                    $pages++;
                    $page++;

                    if (! (count($rows) === $pageSize && $pageHasToday)) {
                        $modeProgress[$key]['done'] = true;
                        break;
                    }
                }

                $modeProgress[$key]['next_page'] = $page;
            }

            $hasMoreToday = collect($modeProgress)->contains(fn ($progress) => ! $progress['done']);

            if ($todayRows > 0) {
                $rollups->rebuildMerchantDay($merchant, $date, $merchant->gateway.'_backfill_today');
            }

            $cursor->fill([
                'last_synced_at' => now('Asia/Jakarta'),
                'last_payload_at' => $merchant->topupRequests()->whereDate('submitted_at', $date)->latest('submitted_at')->value('submitted_at'),
                'meta' => [
                    'date' => $date,
                    'mode_progress' => $modeProgress,
                    'page_size' => $pageSize,
                    'pages' => $pages,
                    'transactions' => $total,
                    'skipped' => $skipped,
                    'today_rows' => $todayRows,
                    'status' => $hasMoreToday ? 'running' : 'completed',
                    'completed_at' => $hasMoreToday ? null : now('Asia/Jakarta')->toIso8601String(),
                ],
            ])->save();

            GatewaySyncLog::query()->create([
                'merchant_id' => $merchant->id,
                'gateway' => $merchant->gateway,
                'direction' => 'backfill',
                'endpoint' => '/api/v1/transactions',
                'http_status' => 200,
                'status' => 'success',
                'message' => $hasMoreToday ? 'Today backfill batch completed; more pages remain.' : 'Today backfill completed.',
                'request_meta' => ['date' => $date, 'modes' => $modes, 'pages_per_run' => $pagesPerRun],
                'response_meta' => ['pages' => $pages, 'transactions' => $total, 'skipped' => $skipped, 'today_rows' => $todayRows, 'has_more_today' => $hasMoreToday, 'mode_progress' => $modeProgress],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            if ($hasMoreToday) {
                $dispatcher->dispatchBackfill($merchant->id, $date);
            }
        } catch (\Throwable $exception) {
            GatewaySyncLog::query()->create([
                'merchant_id' => $merchant?->id,
                'gateway' => $merchant?->gateway ?? 'hilogate',
                'direction' => 'backfill',
                'endpoint' => '/api/v1/transactions',
                'http_status' => $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response?->status() : null,
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'request_meta' => ['date' => $date],
                'response_meta' => [],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            throw $exception;
        } finally {
            if ($merchant) {
                $dispatcher->releaseBackfill($merchant->id);
            }
        }
    }

    /**
     * The regular hourly sync re-dispatches this job to keep walking backward
     * through Hilogate's unfiltered /api/v1/transactions feed until it stops
     * seeing `$date`'s rows (see modesToTry()'s docblock - that endpoint does
     * not honor the from/until filter we send, so this page-walk is the only
     * way to bound it). For a high-volume merchant that walk can need far more
     * pages than fit in backfill_pages_per_run before midnight WIB arrives, at
     * which point `$date` is no longer "today" and every future dispatch for
     * it hits the guard above and silently no-ops - previously with no trace.
     * This logs a `failed` GatewaySyncLog once so an incomplete backfill shows
     * up in monitoring instead of being discovered later via a manual audit.
     */
    private function logIfAbandonedIncomplete(Merchant $merchant, string $date, string $cursorType): void
    {
        $cursor = SyncCursor::query()
            ->where('merchant_id', $merchant->id)
            ->where('gateway', $merchant->gateway)
            ->where('cursor_type', $cursorType)
            ->first();

        $meta = is_array($cursor?->meta) ? $cursor->meta : [];
        if (($meta['date'] ?? null) !== $date || ($meta['status'] ?? null) !== 'running') {
            return;
        }

        GatewaySyncLog::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'direction' => 'backfill',
            'endpoint' => '/api/v1/transactions',
            'http_status' => null,
            'status' => 'failed',
            'message' => "Backfill for {$date} abandoned incomplete: day rolled over before it finished walking Hilogate's unfiltered feed. Some transactions for this date may be missing - cross-check manually.",
            'request_meta' => ['date' => $date],
            'response_meta' => ['mode_progress' => $meta['mode_progress'] ?? null, 'pages' => $meta['pages'] ?? null, 'transactions' => $meta['transactions'] ?? null],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $cursor->forceFill(['meta' => array_merge($meta, ['status' => 'abandoned'])])->save();
    }

    private function payloadDate(array $payload): ?string
    {
        $value = $payload['paid_at'] ?? $payload['paidAt'] ?? $payload['created_at'] ?? $payload['createdAt'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;
            return \Carbon\CarbonImmutable::createFromTimestampMs($number > 9999999999 ? $number : $number * 1000, 'Asia/Jakarta')->toDateString();
        }

        return \Carbon\CarbonImmutable::parse((string) $value, 'Asia/Jakarta')->toDateString();
    }

    private function pullTransactions(GatewayClientInterface $client, Merchant $merchant, array $filters): array
    {
        if ($merchant->gateway !== 'hilogate') {
            return $client->pullTransactions($merchant, $filters);
        }

        $filters += $this->hilogateWindowFor($this->date ?: now('Asia/Jakarta')->toDateString());

        return $client->pullTransactions($merchant, $filters);
    }

    /**
     * See SyncMerchantTransactions::modesToTry() - Hilogate's /api/v1/transactions
     * and /api/v1/merchants/{id}/qris endpoints are independent data sources, so
     * both are backfilled rather than stopping at whichever returns rows first.
     */
    private function modesToTry(Merchant $merchant): array
    {
        if ($merchant->gateway !== 'hilogate') {
            return [null];
        }

        return $merchant->merchant_type === 'script' ? ['transactions', 'qris'] : ['qris', 'transactions'];
    }

    private function hilogateWindowFor(string $date): array
    {
        $day = \Carbon\CarbonImmutable::parse($date, 'Asia/Jakarta');

        return [
            'from' => $day->startOfDay()->utc()->format('Y-m-d\TH:i:s').'Z',
            'to' => $day->endOfDay()->utc()->format('Y-m-d\TH:i:s').'Z',
        ];
    }
}
