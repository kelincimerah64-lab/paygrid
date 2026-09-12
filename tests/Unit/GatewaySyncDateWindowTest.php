<?php

namespace Tests\Unit;

use App\Jobs\BackfillMerchantTransactions;
use App\Jobs\SyncMerchantTransactions;
use App\Models\Merchant;
use App\Services\Gateway\GatewayManager;
use App\Services\GatewayBalanceService;
use App\Services\GatewaySyncDispatcher;
use App\Services\MetricRollupService;
use App\Services\TransactionIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewaySyncDateWindowTest extends TestCase
{
    use RefreshDatabase;

    private function approvedHilogateMerchant(): Merchant
    {
        return Merchant::query()->create([
            'slug' => 'window-test-store',
            'name' => 'Window Test Store',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'merchant_id' => 'store-window-test',
            'merchant_key' => 'store-window-secret',
            'approval_status' => 'approved',
        ]);
    }

    public function test_live_sync_defaults_to_a_bounded_recent_window_when_no_filters_given(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');
        config()->set('paygrid.gateway_sync.window_hours', 24);

        Http::fake([
            'https://app.hilogate.test/*' => Http::response(['data' => []]),
        ]);

        $merchant = $this->approvedHilogateMerchant();

        app()->call([app(SyncMerchantTransactions::class, ['merchantId' => $merchant->id, 'filters' => []]), 'handle']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/qris')) {
                return false;
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return isset($query['from'], $query['until'])
                && str_ends_with($query['from'], 'Z')
                && str_ends_with($query['until'], 'Z')
                && $query['from'] < $query['until'];
        });
    }

    public function test_explicit_filters_are_not_overridden_by_the_default_window(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');

        Http::fake([
            'https://app.hilogate.test/*' => Http::response(['data' => []]),
        ]);

        $merchant = $this->approvedHilogateMerchant();

        app()->call([app(SyncMerchantTransactions::class, [
            'merchantId' => $merchant->id,
            'filters' => ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-01-02T00:00:00Z'],
        ]), 'handle']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/qris')) {
                return false;
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['from'] ?? null) === '2026-01-01T00:00:00Z'
                && ($query['until'] ?? null) === '2026-01-02T00:00:00Z';
        });
    }

    public function test_backfill_bounds_the_pull_to_the_specific_day(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');

        Http::fake([
            'https://app.hilogate.test/*' => Http::response(['data' => []]),
        ]);

        $merchant = $this->approvedHilogateMerchant();
        $date = now('Asia/Jakarta')->toDateString();

        app()->call([app(BackfillMerchantTransactions::class, ['merchantId' => $merchant->id, 'date' => $date]), 'handle']);

        Http::assertSent(function ($request) use ($date) {
            if (! str_contains($request->url(), '/qris')) {
                return false;
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $expectedFrom = \Carbon\CarbonImmutable::parse($date, 'Asia/Jakarta')->startOfDay()->utc()->format('Y-m-d\TH:i:s').'Z';

            return ($query['from'] ?? null) === $expectedFrom;
        });
    }
}
