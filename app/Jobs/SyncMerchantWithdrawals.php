<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Services\Gateway\HilogateClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Hilogate's /api/v1/withdrawals has no date filter and returns a merchant's
 * full history newest-first, so this pages through it only until it reaches a
 * withdrawal id already stored locally - the normal case is a 1-page, mostly
 * no-op run. Only Hilogate merchants have this endpoint; other gateways are
 * skipped until/unless they expose an equivalent.
 */
class SyncMerchantWithdrawals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 55;

    public function __construct(public readonly int $merchantId) {}

    public function handle(HilogateClient $client): void
    {
        $merchant = Merchant::query()->find($this->merchantId);

        if (! $merchant || $merchant->gateway !== 'hilogate' || ! $merchant->merchant_id) {
            return;
        }

        $page = 1;
        $pageSize = 100;
        $maxPages = 10;
        $upserted = 0;

        try {
            do {
                $rows = $client->pullWithdrawals($merchant, ['page' => $page, 'page_size' => $pageSize]);

                $knownAlready = 0;
                foreach ($rows as $row) {
                    if (empty($row['id'])) {
                        continue;
                    }

                    $wasRecentlyCreated = MerchantWithdrawal::query()->where('gateway_withdrawal_id', $row['id'])->doesntExist();

                    MerchantWithdrawal::query()->updateOrCreate(
                        ['gateway_withdrawal_id' => $row['id']],
                        [
                            'merchant_id' => $merchant->id,
                            'gateway' => 'hilogate',
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

                    $upserted++;
                    if (! $wasRecentlyCreated) {
                        $knownAlready++;
                    }
                }

                $page++;
                // Every row on this page was already synced - the rest of the
                // (newest-first) history is older still, safe to stop here.
                $reachedKnownHistory = count($rows) > 0 && $knownAlready === count($rows);
            } while (count($rows) === $pageSize && $page <= $maxPages && ! $reachedKnownHistory);
        } catch (\Throwable $exception) {
            Log::warning('paygrid.merchant_withdrawal_sync.failed', [
                'merchant_id' => $merchant->id,
                'message' => $exception->getMessage(),
            ]);

            return;
        }
    }
}
