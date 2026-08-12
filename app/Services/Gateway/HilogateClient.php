<?php

namespace App\Services\Gateway;

use App\Models\Merchant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HilogateClient implements GatewayClientInterface
{
    private ?string $onboardingCookie = null;

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

    public function getBalanceInfo(Merchant $merchant): array
    {
        $path = '/api/v1/merchants/'.urlencode((string) $merchant->merchant_id).'/balance-mutations/info';

        return $this->request($merchant, 'GET', $path, ['with_pending_balance' => 'true'])->json();
    }

    public function pullSettlements(Merchant $merchant, array $filters = []): array
    {
        $path = '/api/v1/merchants/'.urlencode((string) $merchant->merchant_id).'/settlements';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(200, max(1, (int) ($filters['page_size'] ?? 100)));
        $query = array_filter([
            'page' => $page,
            'page_size' => $pageSize,
            'status' => $filters['status'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $response = $this->request($merchant, 'GET', $path, $query)->json();
        $rows = $response['data'] ?? $response['settlements']['data'] ?? $response['response']['data'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function pullTransactions(Merchant $merchant, array $filters = []): array
    {
        $mode = (string) ($filters['pull_mode'] ?? config('paygrid.gateway.hilogate.pull_mode', 'qris'));
        $path = $mode === 'transactions'
            ? '/api/v1/transactions'
            : '/api/v1/merchants/'.urlencode((string) $merchant->merchant_id).'/qris';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? config('paygrid.gateway_sync.page_size', 50))));
        $query = array_filter([
            'page' => $page,
            'page_size' => $pageSize,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'status' => $filters['status'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $response = $this->request($merchant, 'GET', $path, $query)->json();
        $rows = $response['data'] ?? $response['qris']['data'] ?? $response['transactions']['data'] ?? $response['response']['data'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function createMerchant(array $payload): array
    {
        $response = $this->onboardingRequest('/api/v1/onboarding/merchants', 'POST', [
            'merchant_group_id' => (string) ($payload['merchant_group_id'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'status' => strtoupper((string) ($payload['status'] ?? 'PENDING')),
            'transaction_callback_url' => (string) ($payload['transaction_callback_url'] ?? config('paygrid.gateway.hilogate.transaction_callback_url')),
            'withdrawal_callback_url' => (string) ($payload['withdrawal_callback_url'] ?? config('paygrid.gateway.hilogate.withdrawal_callback_url')),
            'dashboard_ip_whitelist' => $this->asList($payload['dashboard_ip_whitelist'] ?? []),
            'api_ip_whitelist' => $this->asList($payload['api_ip_whitelist'] ?? [config('paygrid.security.server_ip')]),
            'is_whitelist_enabled' => (bool) ($payload['is_whitelist_enabled'] ?? true),
            'transaction_payment_gateway_ids' => $this->asList($payload['transaction_payment_gateway_ids'] ?? []),
            'withdrawal_payment_gateway_ids' => $this->asList($payload['withdrawal_payment_gateway_ids'] ?? []),
            'transaction_fee_percentage' => (float) ($payload['transaction_fee_percentage'] ?? 0),
            'second_transaction_fee_percentage' => (float) ($payload['second_transaction_fee_percentage'] ?? 0),
            'third_transaction_fee_percentage' => (float) ($payload['third_transaction_fee_percentage'] ?? 0),
            'withdrawal_fee' => (float) ($payload['withdrawal_fee'] ?? 0),
            'withdrawal_fee_percentage' => (float) ($payload['withdrawal_fee_percentage'] ?? 0),
            'qris_limit' => 2000000,
            'va_payment_gateway_ids' => [],
            'va_fee_percentage' => 0,
        ]);
        $data = (array) ($response['data'] ?? []);

        return [
            ...$response,
            'merchantId' => (string) ($data['id'] ?? $data['merchant_id'] ?? $response['merchant_id'] ?? ''),
            'merchantKey' => (string) ($data['merchant_key'] ?? $data['merchantKey'] ?? $data['secret_key'] ?? $data['api_key'] ?? ''),
        ];
    }

    private function onboardingRequest(string $path, string $method, array $body = []): array
    {
        $email = (string) config('paygrid.gateway.hilogate.onboarding_email');
        $password = (string) config('paygrid.gateway.hilogate.onboarding_password');
        if ($email === '' || $password === '') {
            throw new \RuntimeException('Credential onboarding Hilogate belum dikonfigurasi.');
        }

        if (! $this->onboardingCookie) {
            $login = $this->http()->post($this->host().'/api/v1/auth/signin', ['email' => $email, 'password' => $password])->throw();
            $this->onboardingCookie = $this->cookieFromHeaders($login->headers());
        }

        $response = $this->http()->withHeaders(['Cookie' => $this->onboardingCookie])->post($this->host().$path, $body);
        if ($response->status() === 401) {
            $this->onboardingCookie = null;
            return $this->onboardingRequest($path, $method, $body);
        }

        return $response->throw()->json();
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout((int) config('paygrid.gateway.hilogate.timeout', 4))
            ->retry(2, 500)
            ->acceptJson()
            ->withOptions($this->curlOptions());
    }

    private function host(): string
    {
        $base = (string) config('paygrid.gateway.hilogate.base_url');
        return Str::endsWith($base, '/api') ? Str::beforeLast($base, '/api') : $base;
    }

    private function cookieFromHeaders(array $headers): string
    {
        $values = $headers['set-cookie'] ?? [];
        $value = is_array($values) ? ($values[0] ?? '') : (string) $values;

        return trim(explode(';', $value)[0] ?? '');
    }

    private function asList(mixed $value): array
    {
        if (is_array($value)) return array_values(array_filter(array_map('strval', $value)));
        return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', (string) $value))));
    }

    private function request(Merchant $merchant, string $method, string $path, array $data = []): Response
    {
        $secret = (string) ($merchant->merchant_key ?: config('paygrid.gateway.hilogate.secret_key'));
        $merchantId = (string) ($merchant->merchant_id ?: config('paygrid.gateway.hilogate.merchant_id'));

        if ($merchantId === '' || $secret === '') {
            throw new \RuntimeException('Credential Hilogate belum dikonfigurasi.');
        }

        $bodyString = $method === 'POST' ? json_encode($data, JSON_THROW_ON_ERROR) : '';
        $baseUrl = (string) config('paygrid.gateway.hilogate.base_url');
        $host = Str::endsWith($baseUrl, '/api') ? Str::beforeLast($baseUrl, '/api') : $baseUrl;
        $request = Http::timeout((int) config('paygrid.gateway.hilogate.timeout', 4))
            ->retry(2, 500)
            ->acceptJson()
            ->withOptions($this->curlOptions())
            ->withHeaders([
                'X-Merchant-ID' => $merchantId,
                'X-Signature' => md5($path.$bodyString.$secret),
                'X-Environment' => config('paygrid.gateway.hilogate.environment', 'sandbox'),
                'X-Request-ID' => $merchant->slug.'-'.Str::uuid(),
            ]);

        return $method === 'POST'
            ? $request->withBody($bodyString, 'application/json')->post($host.$path)->throw()
            : $request->get($host.$path, $data)->throw();
    }

    private function curlOptions(): array
    {
        $options = ['verify' => config('paygrid.gateway.hilogate.ca_bundle') ?: true];
        $resolveIp = (string) config('paygrid.gateway.hilogate.resolve_ip');

        if ($resolveIp !== '') {
            $options['curl'] = [CURLOPT_RESOLVE => ['app.hilogate.com:443:'.$resolveIp]];
        }

        return $options;
    }
}
