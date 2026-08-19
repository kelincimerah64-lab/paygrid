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

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $merchantId, public readonly ?string $date = null) {}

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
            if (! $merchant || $merchant->approval_status !== 'approved' || ! $merchant->merchant_id || $date !== now('Asia/Jakarta')->toDateString()) {
                return;
            }

            $cursor = SyncCursor::query()->firstOrNew([
                'merchant_id' => $merchant->id,
                'gateway' => $merchant->gateway,
                'cursor_type' => $cursorType,
            ]);
            $meta = is_array($cursor->meta) ? $cursor->meta : [];
            $page = (($meta['date'] ?? null) === $date) ? max($livePages + 1, (int) ($meta['next_page'] ?? ($livePages + 1))) : ($livePages + 1);

            $client = $gateways->for($merchant);
            $total = 0;
            $skipped = 0;
            $pages = 0;
            $todayRows = 0;
            $hasMoreToday = false;
            $pullMode = null;

            while ($pages < $pagesPerRun) {
                $filters = [
                    'page' => $page,
                    'page_size' => $pageSize,
                ];
                $filters['pull_mode'] = $pullMode;

                [$rows, $pullMode] = $this->pullTransactions($client, $merchant, $filters);

                if ($rows === []) {
                    $hasMoreToday = false;
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

                $pages++;
                $page++;
                $hasMoreToday = count($rows) === $pageSize && $pageHasToday;
                if (! $hasMoreToday) {
                    break;
                }
            }

            if ($todayRows > 0) {
                $rollups->rebuildMerchantDay($merchant, $date, $merchant->gateway.'_backfill_today');
            }

            $cursor->fill([
                'last_synced_at' => now('Asia/Jakarta'),
                'last_payload_at' => $merchant->topupRequests()->whereDate('submitted_at', $date)->latest('submitted_at')->value('submitted_at'),
                'meta' => [
                    'date' => $date,
                    'next_page' => $page,
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
                'request_meta' => ['date' => $date, 'start_page' => $page - $pages, 'pages_per_run' => $pagesPerRun],
                'response_meta' => ['pages' => $pages, 'transactions' => $total, 'skipped' => $skipped, 'today_rows' => $todayRows, 'has_more_today' => $hasMoreToday],
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
            return [$client->pullTransactions($merchant, $filters), null];
        }

        $requestedMode = $filters['pull_mode'] ?? null;
        $modes = $requestedMode
            ? [$requestedMode]
            : ($merchant->merchant_type === 'script' ? ['transactions', 'qris'] : ['qris', 'transactions']);

        $lastRows = [];
        $lastMode = $modes[0];
        foreach ($modes as $mode) {
            $rows = $client->pullTransactions($merchant, array_merge($filters, ['pull_mode' => $mode]));
            $lastRows = $rows;
            $lastMode = $mode;
            if ($rows !== [] || (int) ($filters['page'] ?? 1) > 1) {
                break;
            }
        }

        return [$lastRows, $lastMode];
    }
}
