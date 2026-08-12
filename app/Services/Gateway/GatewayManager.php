<?php

namespace App\Services\Gateway;

use App\Models\Merchant;

class GatewayManager
{
    public function for(Merchant $merchant): GatewayClientInterface
    {
        return match ($merchant->gateway) {
            'hilogate' => app(HilogateClient::class),
            default => throw new \RuntimeException("Gateway {$merchant->gateway} belum tersedia untuk transaksi top-up."),
        };
    }
}
