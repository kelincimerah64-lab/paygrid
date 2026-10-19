<?php

namespace Tests\Unit;

use App\Jobs\SyncMerchantTransactions;
use App\Jobs\SyncMerchantWithdrawals;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Services\Gateway\GatewayManager;
use App\Services\Gateway\HilogateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UG Ultimate's merchants run on app.magnetopay.com, a white-label of the
 * same Hilogate backend - same merchant_id/secret_key and API shape, only
 * the host differs. HilogateClient serves both, keyed off $merchant->gateway.
 */
class MagnetopayGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function magnetopayMerchant(): Merchant
    {
        return Merchant::query()->create([
            'slug' => 'ug-magnetopay-store',
            'name' => 'UG Magnetopay Store',
            'merchant_type' => 'cm',
            'gateway' => 'magnetopay',
            'merchant_id' => 'store-magnetopay-test',
            'merchant_key' => 'store-magnetopay-secret',
            'approval_status' => 'approved',
        ]);
    }

    public function test_gateway_manager_resolves_magnetopay_to_hilogate_client(): void
    {
        $merchant = $this->magnetopayMerchant();

        $this->assertInstanceOf(HilogateClient::class, app(GatewayManager::class)->for($merchant));
    }

    public function test_requests_for_a_magnetopay_merchant_hit_the_magnetopay_host_not_hilogate(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');
        config()->set('paygrid.gateway.magnetopay.base_url', 'https://app.magnetopay.test/api');

        Http::fake([
            'https://app.magnetopay.test/*' => Http::response(['data' => []]),
            'https://app.hilogate.test/*' => Http::response(['data' => []], 500),
        ]);

        $merchant = $this->magnetopayMerchant();

        app(HilogateClient::class)->pullTransactions($merchant, ['pull_mode' => 'transactions']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://app.magnetopay.test/'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://app.hilogate.test/'));
    }

    public function test_sync_transactions_job_does_not_skip_a_magnetopay_merchant(): void
    {
        config()->set('paygrid.gateway.magnetopay.base_url', 'https://app.magnetopay.test/api');
        config()->set('paygrid.gateway_sync.window_hours', 24);

        Http::fake([
            'https://app.magnetopay.test/*' => Http::response(['data' => []]),
        ]);

        $merchant = $this->magnetopayMerchant();

        app()->call([app(SyncMerchantTransactions::class, ['merchantId' => $merchant->id, 'filters' => []]), 'handle']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://app.magnetopay.test/'));
    }

    public function test_sync_withdrawals_job_does_not_skip_a_magnetopay_merchant(): void
    {
        config()->set('paygrid.gateway.magnetopay.base_url', 'https://app.magnetopay.test/api');

        Http::fake([
            'https://app.magnetopay.test/*' => Http::response(['data' => [
                ['id' => 'wd-1', 'ref_id' => 'ref-1', 'status' => 'COMPLETED', 'amount' => 10000],
            ]]),
        ]);

        $merchant = $this->magnetopayMerchant();

        SyncMerchantWithdrawals::dispatchSync($merchant->id);

        $this->assertDatabaseHas('merchant_withdrawals', [
            'merchant_id' => $merchant->id,
            'gateway_withdrawal_id' => 'wd-1',
            'gateway' => 'magnetopay',
        ]);
    }
}
