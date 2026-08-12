<?php

namespace App\Services\Gateway;

use App\Models\Merchant;

class ArtagetoClient implements GatewayClientInterface
{
    public function createQrisTransaction(Merchant $merchant, string $reference, int $amount, int $expiresInMinutes = 30): array
    {
        throw new \RuntimeException('Gateway Artageto belum tersedia untuk transaksi top-up.');
    }

    public function getTransaction(Merchant $merchant, string $reference): array
    {
        throw new \RuntimeException('Gateway Artageto belum tersedia untuk transaksi top-up.');
    }

    public function pullTransactions(Merchant $merchant, array $filters = []): array
    {
        return [];
    }

    public function pullSettlements(Merchant $merchant, array $filters = []): array
    {
        return [];
    }

    public function createMerchant(array $payload): array
    {
        return [];
    }
}
