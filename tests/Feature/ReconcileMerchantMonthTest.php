<?php

namespace Tests\Feature;

use App\Jobs\ReconcileMerchantMonth;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\ReconciliationRun;
use App\Models\TopupRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileMerchantMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_a_missing_october_transaction_without_duplicating_an_already_known_one(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');
        config()->set('paygrid.gateway.hilogate.merchant_id', 'platform-merchant');
        config()->set('paygrid.gateway.hilogate.secret_key', 'platform-secret');

        $agent = Agent::query()->create(['name' => 'Reconcile Agent', 'code' => 'AG-RECONCILE', 'connection_type' => 'cm', 'is_active' => true]);
        $merchant = Merchant::query()->create([
            'slug' => 'reconcile-store', 'name' => 'Reconcile Store', 'agent_id' => $agent->id,
            'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved',
            'merchant_id' => 'store-reconcile', 'merchant_key' => 'store-secret',
        ]);

        // Already known locally - must be updated, not duplicated.
        TopupRequest::query()->create([
            'merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'ref-existing', 'transaction_id' => 'ref-existing',
            'status' => 'success', 'amount' => 1000000, 'net_amount' => 989000, 'fee_amount' => 11000,
            'customer_reference' => 'REF-EXISTING',
            'submitted_at' => \Carbon\CarbonImmutable::parse('2026-10-10 10:00:00', 'Asia/Jakarta')->utc(),
        ]);

        Http::fake([
            'https://app.hilogate.test/api/v1/merchants/store-reconcile/qris*' => Http::sequence()
                ->push(['data' => [
                    ['id' => 'ref-existing', 'status' => 'SUCCESS', 'amount' => 1000000, 'created_at' => '2026-10-10 10:00:00'],
                    ['id' => 'ref-missing', 'status' => 'SUCCESS', 'amount' => 500000, 'created_at' => '2026-10-15 10:00:00'],
                ]])
                ->push(['data' => []]),
            'https://app.hilogate.test/api/v1/transactions*' => Http::response(['data' => []]),
        ]);

        ReconcileMerchantMonth::dispatchSync($merchant->id, '2026-10');

        $this->assertDatabaseHas('topup_requests', ['gateway_ref_id' => 'ref-missing', 'amount' => 500000]);
        $this->assertSame(2, TopupRequest::query()->where('merchant_id', $merchant->id)->count());

        $run = ReconciliationRun::query()->where('merchant_id', $merchant->id)->where('type', 'collection')->where('month', '2026-10')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->records_added);
        $this->assertSame(1, $run->records_updated);
    }

    public function test_failed_handler_records_the_exception_message_for_the_superadmin_log_column(): void
    {
        $agent = Agent::query()->create(['name' => 'Reconcile Fail Agent', 'code' => 'AG-RECONCILE-FAIL', 'connection_type' => 'cm', 'is_active' => true]);
        $merchant = Merchant::query()->create([
            'slug' => 'reconcile-fail-store', 'name' => 'Reconcile Fail Store', 'agent_id' => $agent->id,
            'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved',
            'merchant_id' => 'store-reconcile-fail', 'merchant_key' => 'store-secret',
        ]);
        ReconciliationRun::query()->create([
            'merchant_id' => $merchant->id, 'type' => 'collection', 'month' => '2026-10',
            'status' => 'running', 'started_at' => now(),
        ]);

        $job = new ReconcileMerchantMonth($merchant->id, '2026-10');
        $job->failed(new \RuntimeException('cURL error 28: Operation timed out after 20002 milliseconds'));

        $run = ReconciliationRun::query()->where('merchant_id', $merchant->id)->where('type', 'collection')->where('month', '2026-10')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('cURL error 28', $run->last_error);
    }
}
