<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantGatewayBalance;
use App\Services\Gateway\GatewayManager;

class GatewayBalanceService
{
    public function __construct(private readonly GatewayManager $gateways) {}

    public function current(Merchant $merchant): array
    {
        $balance = MerchantGatewayBalance::query()
            ->where('merchant_id', $merchant->id)
            ->where('gateway', $merchant->gateway)
            ->first();

        return [
            'active' => (int) ($balance?->active_balance ?? 0),
            'pending' => (int) ($balance?->pending_balance ?? 0),
            'source' => $balance ? 'db' : 'none',
            'synced_at' => $balance?->synced_at,
        ];
    }

    public function refresh(Merchant $merchant): MerchantGatewayBalance
    {
        $payload = $this->gateways->for($merchant)->getBalanceInfo($merchant);
        $parsed = $this->extract($payload);

        return MerchantGatewayBalance::query()->updateOrCreate(
            ['merchant_id' => $merchant->id, 'gateway' => $merchant->gateway],
            [
                'active_balance' => $parsed['active'],
                'pending_balance' => $parsed['pending'],
                'payload' => $payload,
                'synced_at' => now(),
            ],
        );
    }

    public function extract(array $payload): array
    {
        $source = $payload['data'] ?? $payload['response'] ?? $payload;
        $number = static function (array $keys) use ($source): int {
            foreach ($keys as $key) {
                $value = $source[$key] ?? ($source['data'][$key] ?? null) ?? ($source['response'][$key] ?? null);
                if ($value !== null && $value !== '') {
                    if (is_int($value) || is_float($value)) {
                        return (int) round((float) $value);
                    }

                    $normalized = trim((string) $value);
                    if (str_contains($normalized, '.') && str_contains($normalized, ',')) {
                        $normalized = str_replace('.', '', $normalized);
                        $normalized = str_replace(',', '.', $normalized);
                    } elseif (str_contains($normalized, ',')) {
                        $normalized = str_replace(',', '.', $normalized);
                    } elseif (preg_match('/^-?\d+\.\d{1,3}$/', $normalized) === 1) {
                        // Gateway JSON decimals may arrive as strings like "90413723.304".
                        $normalized = $normalized;
                    } else {
                        $normalized = str_replace('.', '', $normalized);
                    }

                    return (int) round((float) preg_replace('/[^0-9.\-]/', '', $normalized));
                }
            }

            return 0;
        };

        return [
            'active' => $number(['active_balance', 'activeBalance', 'available_balance', 'availableBalance', 'available', 'balance', 'current_balance', 'currentBalance']),
            'pending' => $number(['pending_balance', 'pendingBalance', 'pending', 'pending_amount', 'pendingAmount', 'hold_balance', 'holdBalance', 'unsettled_balance', 'unsettledBalance']),
        ];
    }
}
