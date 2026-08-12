<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\AuditLogService;
use App\Services\Gateway\GatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProvisionMerchantOnGateway implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 45;

    public function __construct(public readonly int $merchantId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('paygrid:provision-merchant:'.$this->merchantId))->expireAfter(180)->releaseAfter(10)];
    }

    public function handle(GatewayManager $gateways, AuditLogService $audit): void
    {
        $merchant = Merchant::query()->findOrFail($this->merchantId);
        if ($merchant->provisioning_status === 'success' && $merchant->merchant_id) {
            return;
        }

        $merchant->forceFill([
            'provisioning_status' => 'processing',
            'provisioning_error' => null,
            'provisioning_attempts' => $merchant->provisioning_attempts + 1,
        ])->save();

        try {
            $response = $gateways->for($merchant)->createMerchant([
            'name' => $merchant->name,
            'merchant_group_id' => $merchant->merchant_group_id,
            'transaction_callback_url' => $merchant->transaction_callback_url,
            'withdrawal_callback_url' => $merchant->withdrawal_callback_url,
            'api_ip_whitelist' => [config('paygrid.security.server_ip')],
            'is_whitelist_enabled' => true,
            ]);
            $safeResponse = Arr::except($response, ['merchantKey', 'merchant_key', 'secret_key', 'api_key']);
            if (isset($safeResponse['data']) && is_array($safeResponse['data'])) {
                $safeResponse['data'] = Arr::except($safeResponse['data'], ['merchant_key', 'secret_key', 'api_key']);
            }
            $before = $merchant->only(['merchant_id', 'provisioning_status']);
            $merchant->forceFill([
                'merchant_id' => ($response['merchantId'] ?? '') ?: $merchant->merchant_id,
                'merchant_key' => ($response['merchantKey'] ?? '') ?: $merchant->merchant_key,
                'provisioning_status' => 'success',
                'provisioned_at' => now(),
                'onboarding_payload' => array_merge((array) $merchant->onboarding_payload, ['gateway_response' => $safeResponse]),
            ])->save();
            $audit->record('merchant.gateway_provisioned', $merchant, $before, $merchant->only(['merchant_id', 'provisioning_status', 'provisioned_at']));
        } catch (\Throwable $exception) {
            $merchant->forceFill([
                'provisioning_status' => 'failed',
                'provisioning_error' => $exception->getMessage(),
            ])->save();
            $audit->record('merchant.gateway_provision_failed', $merchant, null, $merchant->only(['provisioning_status', 'provisioning_error', 'provisioning_attempts']));
            throw $exception;
        }
    }
}
