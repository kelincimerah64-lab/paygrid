<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\GatewaySyncLog;
use App\Models\SyncCursor;
use App\Services\Gateway\GatewayManager;
use App\Services\GatewayBalanceService;
use App\Services\TransactionIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMerchantTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public readonly int $merchantId, public readonly array $filters = []) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('paygrid:gateway-sync:merchant:'.$this->merchantId))->expireAfter(120)->releaseAfter(5)];
    }

    public function handle(GatewayManager $gateways, TransactionIngestionService $ingestion, GatewayBalanceService $balances): void
    {
        $startedAt = now();
        $merchant = Merchant::query()->find($this->merchantId);

        if (! $merchant || $merchant->approval_status !== 'approved' || ! $merchant->merchant_id) {
            return;
        }

        try {
            $client = $gateways->for($merchant);
            $filters = $this->filters;

            $page = 1;
            $total = 0;
            $pageSize = (int) config('paygrid.gateway_sync.page_size', 50);

            do {
                $rows = $client->pullTransactions($merchant, array_merge($filters, [
                    'page' => $page,
                    'page_size' => $pageSize,
                ]));

                foreach ($rows as $payload) {
                    $ingestion->ingestForMerchant($merchant, $payload, $merchant->gateway, $merchant->gateway.'_pull', true);
                    $total++;
                }

                $page++;
            } while (count($rows) === $pageSize && $page <= 10);

            if ($merchant->gateway === 'hilogate') {
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
                    'meta' => ['pages' => $page - 1, 'transactions' => $total],
                ],
            );

            GatewaySyncLog::query()->create([
                'merchant_id' => $merchant->id,
                'gateway' => $merchant->gateway,
                'direction' => 'pull',
                'endpoint' => config('paygrid.gateway.hilogate.pull_mode', 'qris') === 'transactions' ? '/api/v1/transactions' : '/api/v1/merchants/{merchant_id}/qris',
                'http_status' => 200,
                'status' => 'success',
                'message' => 'Gateway polling completed.',
                'request_meta' => ['filters' => $this->filters],
                'response_meta' => ['pages' => $page - 1, 'transactions' => $total],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            Log::info('paygrid.gateway_sync.completed', ['merchant_id' => $merchant->id, 'gateway' => $merchant->gateway, 'transactions' => $total]);
        } catch (\Throwable $exception) {
            GatewaySyncLog::query()->create([
                'merchant_id' => $merchant->id,
                'gateway' => $merchant->gateway,
                'direction' => 'pull',
                'endpoint' => '/api/v1/merchants/{merchant_id}/qris',
                'http_status' => $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response?->status() : null,
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'request_meta' => ['filters' => $this->filters],
                'response_meta' => [],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }
}
