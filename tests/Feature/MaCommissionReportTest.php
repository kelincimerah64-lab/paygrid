<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CommissionConfig;
use App\Models\Merchant;
use App\Models\MerchantCommissionReport;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaCommissionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_recaps_this_mas_stores_only_for_the_selected_month(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();

        $ownAgent = Agent::query()->create(['name' => 'Report Own Agent', 'code' => 'AG-REPORT-OWN', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $otherAgent = Agent::query()->create(['name' => 'Report Other Agent', 'code' => 'AG-REPORT-OTHER', 'connection_type' => 'cm', 'is_active' => true]);

        $ownStoreWithCommission = Merchant::query()->create(['slug' => 'report-own-a', 'name' => 'Report Own A', 'agent_id' => $ownAgent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $ownStoreNoCommission = Merchant::query()->create(['slug' => 'report-own-b', 'name' => 'Report Own B', 'agent_id' => $ownAgent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $otherStore = Merchant::query()->create(['slug' => 'report-other', 'name' => 'Report Other', 'agent_id' => $otherAgent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);

        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'name' => 'Report Config', 'agent_id' => $ownAgent->id, 'status' => 'active', 'apply_scope' => 'specific']);

        // Inside the queried month (Nov 2026).
        MerchantCommissionReport::query()->create([
            'merchant_id' => $ownStoreWithCommission->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-11-02', 'day_of_week' => 1,
            'volume' => 1000000, 'rate_percent' => 0.2, 'commission_amount' => 2000,
            'report_date' => '2026-11-03',
        ]);
        // Outside the queried month - must not be counted.
        MerchantCommissionReport::query()->create([
            'merchant_id' => $ownStoreWithCommission->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-10-15', 'day_of_week' => 4,
            'volume' => 5000000, 'rate_percent' => 0.2, 'commission_amount' => 10000,
            'report_date' => '2026-10-16',
        ]);
        // Another MA's store - must never leak in.
        MerchantCommissionReport::query()->create([
            'merchant_id' => $otherStore->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-11-02', 'day_of_week' => 1,
            'volume' => 9999999, 'rate_percent' => 0.2, 'commission_amount' => 99999,
            'report_date' => '2026-11-03',
        ]);

        $response = $this->actingAs($ma)->get(route('ma.commission.report', ['month' => '2026-11']));

        $response->assertOk();
        $response->assertSee('Report Own A');
        $response->assertSee('Report Own B');
        $response->assertDontSee('Report Other');
        $response->assertSee('Rp 1.000.000');
        $response->assertSee('Rp 2.000');
        $response->assertDontSee('Rp 10.000');
        $response->assertSee('Belum ada komisi');
    }

    public function test_rows_default_sort_by_highest_commission_first_and_can_be_filtered_by_agent(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();

        $agentA = Agent::query()->create(['name' => 'Report Sort Agent A', 'code' => 'AG-SORT-A', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $agentB = Agent::query()->create(['name' => 'Report Sort Agent B', 'code' => 'AG-SORT-B', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);

        $smallStore = Merchant::query()->create(['slug' => 'report-sort-small', 'name' => 'Report Sort Small', 'agent_id' => $agentA->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $bigStore = Merchant::query()->create(['slug' => 'report-sort-big', 'name' => 'Report Sort Big', 'agent_id' => $agentB->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);

        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'name' => 'Report Sort Config', 'agent_id' => $agentA->id, 'status' => 'active', 'apply_scope' => 'all']);

        MerchantCommissionReport::query()->create([
            'merchant_id' => $smallStore->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-11-02', 'day_of_week' => 1,
            'volume' => 100000, 'rate_percent' => 0.2, 'commission_amount' => 200,
            'report_date' => '2026-11-03',
        ]);
        MerchantCommissionReport::query()->create([
            'merchant_id' => $bigStore->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-11-02', 'day_of_week' => 1,
            'volume' => 5000000, 'rate_percent' => 0.2, 'commission_amount' => 10000,
            'report_date' => '2026-11-03',
        ]);

        $response = $this->actingAs($ma)->get(route('ma.commission.report', ['month' => '2026-11']));
        $response->assertOk();
        $response->assertSeeInOrder(['Report Sort Big', 'Report Sort Small']);

        $filtered = $this->actingAs($ma)->get(route('ma.commission.report', ['month' => '2026-11', 'agent_id' => $agentA->id]));
        $filtered->assertOk();
        $filtered->assertSee('Report Sort Small');
        $filtered->assertDontSee('Report Sort Big');
    }

    public function test_revenue_trx_and_cashback_cover_the_whole_month_regardless_of_commission_config(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();

        $agent = Agent::query()->create(['name' => 'Report Cashback Agent', 'code' => 'AG-CASHBACK', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $store = Merchant::query()->create([
            'slug' => 'report-cashback', 'name' => 'Report Cashback Store', 'agent_id' => $agent->id,
            'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved',
            'cashback_agent_percent' => 2,
        ]);

        // No CommissionConfig at all for this store - Revenue/TRX/CashBack must still show up.
        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'report-cashback-a', 'transaction_id' => 'report-cashback-a',
            'status' => 'success', 'amount' => 1000000, 'net_amount' => 989000, 'fee_amount' => 11000,
            'customer_reference' => 'REF-REPORT-CASHBACK-A',
            'submitted_at' => \Carbon\CarbonImmutable::parse('2026-11-05', 'Asia/Jakarta')->setTime(10, 0)->utc(),
        ]);
        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'report-cashback-b', 'transaction_id' => 'report-cashback-b',
            'status' => 'success', 'amount' => 500000, 'net_amount' => 494500, 'fee_amount' => 5500,
            'customer_reference' => 'REF-REPORT-CASHBACK-B',
            'submitted_at' => \Carbon\CarbonImmutable::parse('2026-11-20', 'Asia/Jakarta')->setTime(10, 0)->utc(),
        ]);

        $response = $this->actingAs($ma)->get(route('ma.commission.report', ['month' => '2026-11']));

        $response->assertOk();
        $response->assertSee('Report Cashback Store');
        $response->assertSee('Rp 1.500.000'); // Revenue: 1,000,000 + 500,000.
        $response->assertSee('Rp 30.000'); // CashBack: 1,500,000 x 2%.
        $response->assertSee('Belum ada komisi'); // No commission config, so still 0 in the Komisi column.

        $row = $response->viewData('rows')->firstWhere('name', 'Report Cashback Store');
        $this->assertSame(2, $row['trx']);
        $this->assertSame(1500000, $row['revenue']);
        $this->assertSame(30000, $row['cashback']);
    }
}
