<?php

namespace Tests\Unit;

use App\Jobs\BackfillMerchantTransactions;
use App\Jobs\SyncMerchantTransactions;
use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Models\SyncCursor;
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

    public function test_backfill_job_retries_with_backoff_instead_of_failing_permanently_on_first_timeout(): void
    {
        $job = new BackfillMerchantTransactions(1, '2026-09-01');

        $this->assertSame(4, $job->tries);
        $this->assertSame([15, 45, 90], $job->backoff());
    }

    public function test_backfill_logs_a_failure_when_a_running_day_is_abandoned_because_it_rolled_over(): void
    {
        $merchant = $this->approvedHilogateMerchant();
        $yesterday = now('Asia/Jakarta')->subDay()->toDateString();

        SyncCursor::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => 'hilogate',
            'cursor_type' => 'transaction_backfill_today',
            'meta' => ['date' => $yesterday, 'status' => 'running', 'mode_progress' => ['qris' => ['next_page' => 42, 'done' => false]]],
        ]);

        app()->call([app(BackfillMerchantTransactions::class, ['merchantId' => $merchant->id, 'date' => $yesterday]), 'handle']);

        $this->assertDatabaseHas('gateway_sync_logs', [
            'merchant_id' => $merchant->id,
            'direction' => 'backfill',
            'status' => 'failed',
        ]);
        $this->assertSame('abandoned', SyncCursor::query()->where('merchant_id', $merchant->id)->first()->meta['status']);
    }

    public function test_backfill_does_not_log_a_failure_when_the_day_already_completed(): void
    {
        $merchant = $this->approvedHilogateMerchant();
        $yesterday = now('Asia/Jakarta')->subDay()->toDateString();

        SyncCursor::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => 'hilogate',
            'cursor_type' => 'transaction_backfill_today',
            'meta' => ['date' => $yesterday, 'status' => 'completed'],
        ]);

        app()->call([app(BackfillMerchantTransactions::class, ['merchantId' => $merchant->id, 'date' => $yesterday]), 'handle']);

        $this->assertDatabaseCount('gateway_sync_logs', 0);
    }
}
