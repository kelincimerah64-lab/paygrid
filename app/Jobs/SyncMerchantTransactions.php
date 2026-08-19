<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\GatewaySyncLog;
use App\Models\SyncCursor;
use App\Models\TopupRequest;
use App\Services\Gateway\GatewayManager;
use App\Services\Gateway\GatewayClientInterface;
use App\Services\GatewayBalanceService;
use App\Services\GatewaySyncDispatcher;
use App\Services\MetricRollupService;
use App\Services\TransactionIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMerchantTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 55;

    public function __construct(public readonly int $merchantId, public readonly array $filters = []) {}

    public function handle(GatewayManager $gateways, TransactionIngestionService $ingestion, GatewayBalanceService $balances, GatewaySyncDispatcher $dispatcher, MetricRollupService $rollups): void
    {
        $startedAt = now();
        $merchant = Merchant::query()->find($this->merchantId);
        $statusChecks = 0;

        try {
            if (! $merchant || $merchant->approval_status !== 'approved' || ! $merchant->merchant_id) {
                return;
            }

            $client = $gateways->for($merchant);
            $filters = $this->filters;
            $pullMode = $filters['pull_mode'] ?? null;

            $page = 1;
            $total = 0;
            $skipped = 0;
            $dates = [];
            $pageSize = max(1, min(100, (int) ($filters['page_size'] ?? config('paygrid.gateway_sync.page_size', 50))));
            $maxPages = max(1, (int) ($filters['max_pages'] ?? config('paygrid.gateway_sync.max_pages', 10)));
            unset($filters['max_pages']);
            unset($filters['page_size']);
            $statusChecks = $this->syncPendingReferences($merchant, $client, $ingestion, $dates);

            do {
                [$rows, $pullMode] = $this->pullTransactions($client, $merchant, array_merge($filters, [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'pull_mode' => $pullMode,
                ]));

                foreach ($rows as $payload) {
                    $amount = (int) preg_replace('/\D+/', '', (string) ($payload['amount'] ?? 0));
                    if ((float) ($payload['amount'] ?? 0) <= 0 || $amount <= 0) {
                        $skipped++;
                        continue;
                    }

                    $request = $ingestion->ingestForMerchant($merchant, $payload, $merchant->gateway, $merchant->gateway.'_pull', true);
                    if ($request->submitted_at) {
                        $dates[$request->submitted_at->toDateString()] = true;
                    }
                    $total++;
                }

                $page++;
            } while (count($rows) === $pageSize && $page <= $maxPages);

            $hitPageLimit = count($rows) === $pageSize && $page > $maxPages;

            foreach (array_keys($dates) as $date) {
                $rollups->rebuildMerchantDay($merchant, $date, $merchant->gateway.'_pull');
            }

            if (in_array($merchant->gateway, ['hilogate', 'artageto'], true)) {
                try {
                    $balances->refresh($merchant);
                } catch (\Throwable $exception) {
                    Log::warning('paygrid.gateway_balance_sync.failed', ['merchant_id' => $merchant->id, 'gateway' => $merchant->gateway, 'message' => $exception->getMessage()]);
                }
            }

            $latest = $merchant->topupRequests()->latest('submitted_at')->first();
            SyncCursor::query()->updateOrCreate(
                ['merchant_id' => $merchant->id, 'gateway' => $merchant->gateway, 'cursor_type' => 'transaction'],
                [
                    'last_synced_at' => now('Asia/Jakarta'),
                    'last_gateway_ref_id' => $latest?->gateway_ref_id,
                    'last_payload_at' => $latest?->submitted_at,
                    'meta' => ['pages' => $page - 1, 'transactions' => $total, 'skipped' => $skipped, 'status_checks' => $statusChecks, 'hit_page_limit' => $hitPageLimit],
                ],
            );

            if ($this->shouldWriteSuccessLog() || $hitPageLimit) {
                GatewaySyncLog::query()->create([
                    'merchant_id' => $merchant->id,
                    'gateway' => $merchant->gateway,
                    'direction' => 'pull',
                    'endpoint' => $merchant->gateway === 'artageto'
                        ? '/api/v1/merchants/{merchant_id}/qris'
                        : (($pullMode ?? config('paygrid.gateway.hilogate.pull_mode', 'qris')) === 'transactions' ? '/api/v1/transactions' : '/api/v1/merchants/{merchant_id}/qris'),
                    'http_status' => 200,
                    'status' => 'success',
                    'message' => $hitPageLimit ? 'Gateway polling completed; page limit reached.' : 'Gateway polling completed.',
                    'request_meta' => ['filters' => $this->filters],
                    'response_meta' => ['pages' => $page - 1, 'transactions' => $total, 'skipped' => $skipped, 'status_checks' => $statusChecks, 'hit_page_limit' => $hitPageLimit],
                    'started_at' => $startedAt,
                    'finished_at' => now(),
                ]);

            }

            if ($hitPageLimit) {
                $dispatcher->dispatchBackfill($merchant->id, now('Asia/Jakarta')->toDateString());
            }

        } catch (\Throwable $exception) {
            GatewaySyncLog::query()->create([
                'merchant_id' => $merchant->id,
                'gateway' => $merchant->gateway,
                'direction' => 'pull',
                'endpoint' => '/api/v1/merchants/{merchant_id}/qris',
                'http_status' => $exception instanceof RequestException ? $exception->response?->status() : null,
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'request_meta' => ['filters' => $this->filters],
                'response_meta' => ['status_checks' => $statusChecks],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            if ($exception instanceof RequestException) {
                $status = $exception->response?->status();
                $dispatcher->coolDown($this->merchantId, $this->cooldownSeconds($status));

                Log::warning('paygrid.gateway_sync.http_failed', [
                    'merchant_id' => $merchant?->id,
                    'gateway' => $merchant?->gateway,
                    'http_status' => $status,
                    'message' => $exception->getMessage(),
                ]);

                return;
            }

            throw $exception;
        } finally {
            $dispatcher->release($this->merchantId);
        }
    }

    private function shouldWriteSuccessLog(): bool
    {
        return (int) now()->format('s') < 10;
    }

    private function cooldownSeconds(?int $status): int
    {
        return match ($status) {
            401, 403 => 30 * 60,
            429 => 5 * 60,
            default => 3 * 60,
        };
    }

    private function syncPendingReferences(Merchant $merchant, GatewayClientInterface $client, TransactionIngestionService $ingestion, array &$dates): int
    {
        $checked = 0;

        TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('status', ['pending', 'expired'])
            ->whereNotNull('gateway_ref_id')
            ->where(fn ($query) => $query
                ->where('gateway_ref_id', 'like', 'qris\_%')
                ->orWhere('data_source', 'public_submit'))
            ->where('submitted_at', '>=', now('Asia/Jakarta')->subHours(36))
            ->latest('submitted_at')
            ->limit(5)
            ->get()
            ->each(function (TopupRequest $request) use ($merchant, $client, $ingestion, &$dates, &$checked): void {
                try {
                    $response = $client->getTransaction($merchant, $request->gateway_ref_id);
                    $payload = $response['data'] ?? $response;
                    if (! is_array($payload)) {
                        return;
                    }

                    $updated = $ingestion->ingestForMerchant($merchant, $payload, $merchant->gateway, $merchant->gateway.'_status_pull', true);
                    if ($updated->submitted_at) {
                        $dates[$updated->submitted_at->toDateString()] = true;
                    }
                    $checked++;
                } catch (\Throwable $exception) {
                    Log::warning('paygrid.gateway_status_sync.failed', [
                        'merchant_id' => $merchant->id,
                        'topup_request_id' => $request->id,
                        'gateway_ref_id' => $request->gateway_ref_id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

        return $checked;
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
