<?php

namespace App\Services\Gateway;

use App\Models\Merchant;

interface GatewayClientInterface
{
    public function createQrisTransaction(Merchant $merchant, string $reference, int $amount, int $expiresInMinutes = 30): array;

    public function getTransaction(Merchant $merchant, string $reference): array;

    public function pullTransactions(Merchant $merchant, array $filters = []): array;

    public function pullSettlements(Merchant $merchant, array $filters = []): array;

    public function createMerchant(array $payload): array;
}
