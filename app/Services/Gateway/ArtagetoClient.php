<?php

namespace App\Services\Gateway;

use App\Models\Merchant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ArtagetoClient implements GatewayClientInterface
{
    public function createQrisTransaction(Merchant $merchant, string $reference, int $amount, int $expiresInMinutes = 30): array
    {
        $path = '/api/v1/qris';
        $body = [
            'client_reference' => $reference,
            'amount' => $amount,
            'note' => $reference,
            'expires_in' => $expiresInMinutes,
        ];

        return $this->request($merchant, 'POST', $path, $body)->json();
    }

    public function getTransaction(Merchant $merchant, string $reference): array
    {
        return $this->request($merchant, 'GET', '/api/v1/qris/'.urlencode($reference))->json();
    }

    public function pullTransactions(Merchant $merchant, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? config('paygrid.gateway_sync.page_size', 50))));
        $query = array_filter([
            'page' => $page,
            'page_size' => $pageSize,
            'status' => $filters['status'] ?? null,
            'from' => $filters['from'] ?? null,
            'until' => $filters['to'] ?? ($filters['until'] ?? null),
            'reference' => $filters['reference'] ?? null,
            'client_reference' => $filters['client_reference'] ?? null,
            'provider_reference' => $filters['provider_reference'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $response = $this->request($merchant, 'GET', '/api/v1/merchants/'.urlencode((string) $merchant->merchant_id).'/qris', $query)->json();
        $rows = $response['data'] ?? $response['qris']['data'] ?? $response['response']['data'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function pullSettlements(Merchant $merchant, array $filters = []): array
    {
        return [];
    }

    public function createMerchant(array $payload): array
    {
        return [];
    }

    public function getBalanceInfo(Merchant $merchant): array
    {
        $path = '/api/v1/merchants/'.urlencode((string) $merchant->merchant_id).'/balance-mutations/info';

        return $this->request($merchant, 'GET', $path, ['with_pending_balance' => 'true'])->json();
    }

    private function request(Merchant $merchant, string $method, string $path, array $data = []): Response
    {
        $secret = (string) $merchant->merchant_key;
        $merchantId = (string) $merchant->merchant_id;

        if ($merchantId === '' || $secret === '') {
            throw new \RuntimeException('Credential Artageto belum dikonfigurasi.');
        }

        $bodyString = $method === 'POST' || $method === 'PATCH' ? json_encode($data, JSON_THROW_ON_ERROR) : '';
        $request = Http::timeout((int) config('paygrid.gateway.artageto.timeout', 20))
            ->retry(1, 300)
            ->acceptJson()
            ->withHeaders([
                'X-Merchant-ID' => $merchantId,
                'X-Signature' => md5($path.$bodyString.$secret),
                'X-Environment' => config('paygrid.gateway.artageto.environment', 'production'),
                'X-Request-ID' => $merchant->slug.'-'.Str::uuid(),
            ]);

        return match ($method) {
            'POST' => $request->withBody($bodyString, 'application/json')->post($this->host().$path)->throw(),
            'PATCH' => $request->withBody($bodyString, 'application/json')->patch($this->host().$path)->throw(),
            default => $request->get($this->host().$path, $data)->throw(),
        };
    }

    private function host(): string
    {
        $baseUrl = (string) config('paygrid.gateway.artageto.base_url', 'https://app.artageto.com/api');

        return Str::endsWith($baseUrl, '/api') ? Str::beforeLast($baseUrl, '/api') : rtrim($baseUrl, '/');
    }
}
