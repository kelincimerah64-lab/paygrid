<?php

namespace Tests\Unit;

use App\Models\Merchant;
use App\Services\Gateway\HilogateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HilogateClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_pulls_hilogate_qris_transactions_with_expected_signature_and_pagination(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');
        config()->set('paygrid.gateway.hilogate.merchant_id', 'platform-merchant');
        config()->set('paygrid.gateway.hilogate.secret_key', 'platform-secret');
        config()->set('paygrid.gateway.hilogate.environment', 'live');

        Http::fake([
            'https://app.hilogate.test/api/v1/merchants/store-123/qris*' => Http::response([
                'data' => [['id' => 'trx-1', 'status' => 'SUCCESS']],
                'pagination' => ['page' => 2, 'page_size' => 25],
            ]),
        ]);

        $merchant = new Merchant([
            'slug' => 'demo-store',
            'merchant_id' => 'store-123',
            'merchant_key' => 'store-secret',
        ]);

        $rows = app(HilogateClient::class)->pullTransactions($merchant, [
            'page' => 2,
            'page_size' => 25,
            'from' => '2026-08-10',
            'to' => '2026-08-10',
        ]);

        $this->assertSame('trx-1', $rows[0]['id']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://app.hilogate.test/api/v1/merchants/store-123/qris?page=2&page_size=25&from=2026-08-10&to=2026-08-10'
                && $request->header('X-Merchant-ID')[0] === 'store-123'
                && $request->header('X-Environment')[0] === 'live'
                && $request->header('X-Signature')[0] === md5('/api/v1/merchants/store-123/qris'.'store-secret');
        });
    }

    public function test_it_supports_the_hilogate_transactions_endpoint(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');
        config()->set('paygrid.gateway.hilogate.merchant_id', 'platform-merchant');
        config()->set('paygrid.gateway.hilogate.secret_key', 'platform-secret');

        Http::fake([
            'https://app.hilogate.test/api/v1/transactions*' => Http::response([
                'transactions' => ['data' => [['id' => 'trx-2']]],
            ]),
        ]);

        $merchant = new Merchant(['slug' => 'demo-store', 'merchant_id' => 'store-123', 'merchant_key' => 'store-secret']);
        $rows = app(HilogateClient::class)->pullTransactions($merchant, ['pull_mode' => 'transactions']);

        $this->assertSame('trx-2', $rows[0]['id']);
    }

    public function test_it_pulls_hilogate_merchant_settlements_with_expected_signature(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');
        config()->set('paygrid.gateway.hilogate.environment', 'live');

        Http::fake([
            'https://app.hilogate.test/api/v1/merchants/store-123/settlements*' => Http::response([
                'data' => [[
                    'id' => 'set-1',
                    'reference' => 'SET-001',
                    'status' => 'SUCCESS',
                    'amount' => 100000,
                    'fee' => 1000,
                    'net_amount' => 99000,
                ]],
            ]),
        ]);

        $merchant = new Merchant(['slug' => 'demo-store', 'merchant_id' => 'store-123', 'merchant_key' => 'store-secret']);
        $rows = app(HilogateClient::class)->pullSettlements($merchant, ['page' => 2, 'page_size' => 25, 'status' => 'SUCCESS']);

        $this->assertSame('SET-001', $rows[0]['reference']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://app.hilogate.test/api/v1/merchants/store-123/settlements?page=2&page_size=25&status=SUCCESS'
                && $request->header('X-Merchant-ID')[0] === 'store-123'
                && $request->header('X-Environment')[0] === 'live'
                && $request->header('X-Signature')[0] === md5('/api/v1/merchants/store-123/settlements'.'store-secret');
        });
    }
}
