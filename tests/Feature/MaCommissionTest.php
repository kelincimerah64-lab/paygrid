<?php

namespace Tests\Feature;

use App\Jobs\BackfillCommissionReportsThisMonth;
use App\Models\Agent;
use App\Models\CommissionConfig;
use App\Models\Merchant;
use App\Models\MerchantCommissionReport;
use App\Models\MerchantWithdrawal;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MaCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_commission_config_is_saved_with_name_group_and_merchant(): void
    {
        Bus::fake();
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $ownAgent = Agent::query()->create(['name' => 'Commission Own Agent', 'code' => 'AG-COMM-OWN', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);

        $ownStore = Merchant::query()->create(['slug' => 'commission-own', 'name' => 'Commission Own', 'agent_id' => $ownAgent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);

        $days = [];
        for ($d = 0; $d <= 6; $d++) {
            $days[$d] = ['day_of_week' => $d, 'enabled' => $d === 1, 'rate_percent' => 0.10, 'wd_fee_fixed' => 5000, 'notes' => null];
        }

        $response = $this->actingAs($ma)->post(route('ma.commission.store'), [
            'name' => 'Komisi Weekday 0.10%',
            'agent_id' => $ownAgent->id,
            'merchant_id' => $ownStore->id,
            'days' => $days,
        ]);

        $response->assertRedirect();
        Bus::assertDispatched(BackfillCommissionReportsThisMonth::class);
        $config = CommissionConfig::query()->where('ma_user_id', $ma->id)->firstOrFail();
        $this->assertSame('active', $config->status);
        $this->assertSame('Komisi Weekday 0.10%', $config->name);
        $this->assertSame($ownAgent->id, $config->agent_id);
        $this->assertSame('specific', $config->apply_scope);
        $this->assertCount(7, $config->days);
        $this->assertTrue($config->days->firstWhere('day_of_week', 1)->enabled);
        $this->assertSame(0.1, (float) $config->days->firstWhere('day_of_week', 1)->rate_percent);
        $this->assertSame(5000, (int) $config->days->firstWhere('day_of_week', 1)->wd_fee_fixed);
        $this->assertSame([$ownStore->id], $config->merchants->pluck('id')->all());
    }

    public function test_commission_config_leaving_merchant_empty_scopes_to_the_whole_group(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $ownAgent = Agent::query()->create(['name' => 'Commission Group Agent', 'code' => 'AG-COMM-GROUP', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);

        $days = [];
        for ($d = 0; $d <= 6; $d++) {
            $days[$d] = ['day_of_week' => $d, 'enabled' => $d === 1, 'rate_percent' => 0.10, 'notes' => null];
        }

        $response = $this->actingAs($ma)->post(route('ma.commission.store'), [
            'name' => 'Komisi Semua Toko Group',
            'agent_id' => $ownAgent->id,
            'days' => $days,
        ]);

        $response->assertRedirect();
        $config = CommissionConfig::query()->where('ma_user_id', $ma->id)->firstOrFail();
        $this->assertSame('all', $config->apply_scope);
        $this->assertSame($ownAgent->id, $config->agent_id);
        $this->assertCount(0, $config->merchants);
    }

    public function test_commission_config_rejects_an_agent_or_merchant_not_owned_by_this_ma(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $ownAgent = Agent::query()->create(['name' => 'Commission Own Agent 2', 'code' => 'AG-COMM-OWN2', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $otherAgent = Agent::query()->create(['name' => 'Commission Other Agent', 'code' => 'AG-COMM-OTHER', 'connection_type' => 'cm', 'is_active' => true]);
        $otherStore = Merchant::query()->create(['slug' => 'commission-other', 'name' => 'Commission Other', 'agent_id' => $otherAgent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);

        $days = [];
        for ($d = 0; $d <= 6; $d++) {
            $days[$d] = ['day_of_week' => $d, 'enabled' => $d === 1, 'rate_percent' => 0.10, 'notes' => null];
        }

        // Another MA's agent entirely.
        $this->actingAs($ma)->post(route('ma.commission.store'), [
            'name' => 'Should Fail', 'agent_id' => $otherAgent->id, 'days' => $days,
        ])->assertInvalid(['agent_id']);

        // Own agent, but a merchant that belongs to a different agent.
        $this->actingAs($ma)->post(route('ma.commission.store'), [
            'name' => 'Should Fail', 'agent_id' => $ownAgent->id, 'merchant_id' => $otherStore->id, 'days' => $days,
        ])->assertInvalid(['merchant_id']);

        $this->assertSame(0, CommissionConfig::query()->where('ma_user_id', $ma->id)->count());
    }

    public function test_generate_reports_computes_commission_from_the_given_days_successful_volume(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Commission Job Agent', 'code' => 'AG-COMM-JOB', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $store = Merchant::query()->create(['slug' => 'commission-job', 'name' => 'Commission Job', 'agent_id' => $agent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);

        // A Tuesday - CommissionConfig below enables day_of_week=2 (Tuesday).
        $targetDate = '2026-10-20';
        $this->assertSame(2, \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->dayOfWeek);

        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'status' => 'active', 'apply_scope' => 'all']);
        for ($d = 0; $d <= 6; $d++) {
            $config->days()->create(['day_of_week' => $d, 'enabled' => $d === 2, 'rate_percent' => 0.12]);
        }

        // 2 successful trx totalling 1,500,000 on the target date.
        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'commission-job-a', 'transaction_id' => 'commission-job-a',
            'status' => 'success', 'amount' => 1000000, 'net_amount' => 989000, 'fee_amount' => 11000,
            'customer_reference' => 'REF-COMM-JOB-A',
            'submitted_at' => \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->setTime(10, 0)->utc(),
        ]);
        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'commission-job-b', 'transaction_id' => 'commission-job-b',
            'status' => 'success', 'amount' => 500000, 'net_amount' => 494500, 'fee_amount' => 5500,
            'customer_reference' => 'REF-COMM-JOB-B',
            'submitted_at' => \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->setTime(14, 0)->utc(),
        ]);
        // A trx the day before, which must NOT count toward this date's volume.
        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'commission-job-prev', 'transaction_id' => 'commission-job-prev',
            'status' => 'success', 'amount' => 9999999, 'net_amount' => 9900000, 'fee_amount' => 99999,
            'customer_reference' => 'REF-COMM-JOB-PREV',
            'submitted_at' => \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->subDay()->setTime(10, 0)->utc(),
        ]);

        $this->artisan('commission:generate-reports', ['date' => $targetDate])->assertSuccessful();

        $report = MerchantCommissionReport::query()->where('merchant_id', $store->id)->where('transaction_date', $targetDate)->firstOrFail();
        $this->assertSame(1500000, (int) $report->volume);
        // 1,500,000 x 0.12% = 1,800.
        $this->assertSame(1800, (int) $report->commission_amount);
        $this->assertSame('2026-10-21', $report->report_date);

        // Re-running the same date must update the same row, not duplicate it.
        $this->artisan('commission:generate-reports', ['date' => $targetDate])->assertSuccessful();
        $this->assertSame(1, MerchantCommissionReport::query()->where('merchant_id', $store->id)->where('transaction_date', $targetDate)->count());
    }

    public function test_generate_reports_computes_a_flat_wd_fee_per_completed_withdrawal_trx_not_a_percent_of_volume(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Commission WD Agent', 'code' => 'AG-COMM-WD', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $store = Merchant::query()->create(['slug' => 'commission-wd', 'name' => 'Commission WD', 'agent_id' => $agent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);

        $targetDate = '2026-10-20';
        $this->assertSame(2, \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->dayOfWeek);

        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'status' => 'active', 'apply_scope' => 'all']);
        for ($d = 0; $d <= 6; $d++) {
            $config->days()->create(['day_of_week' => $d, 'enabled' => $d === 2, 'rate_percent' => 0, 'wd_fee_fixed' => 5000]);
        }

        // Two completed withdrawals on the target date - both must count.
        MerchantWithdrawal::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'wd-commission-a',
            'status' => 'COMPLETED', 'amount' => 2000000, 'net_amount' => 1980000, 'fee' => 20000,
            'gateway_created_at' => \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->setTime(9, 0)->utc(),
        ]);
        MerchantWithdrawal::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'wd-commission-b',
            'status' => 'COMPLETED', 'amount' => 7000000, 'net_amount' => 6980000, 'fee' => 20000,
            'gateway_created_at' => \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->setTime(10, 0)->utc(),
        ]);
        // A pending withdrawal the same day - must NOT count.
        MerchantWithdrawal::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'wd-commission-pending',
            'status' => 'PENDING', 'amount' => 5000000, 'net_amount' => 0, 'fee' => 0,
            'gateway_created_at' => \Carbon\CarbonImmutable::parse($targetDate, 'Asia/Jakarta')->setTime(11, 0)->utc(),
        ]);

        $this->artisan('commission:generate-reports', ['date' => $targetDate])->assertSuccessful();

        $report = MerchantCommissionReport::query()->where('merchant_id', $store->id)->where('transaction_date', $targetDate)->firstOrFail();
        // Volume is still tracked for context, but is NOT what the fee is based on.
        $this->assertSame(9000000, (int) $report->wd_volume);
        $this->assertSame(2, (int) $report->wd_trx_count);
        // 2 withdrawal trx x flat Rp5.000 = Rp10.000 - regardless of the 9,000,000 volume.
        $this->assertSame(10000, (int) $report->wd_commission_amount);
        $this->assertSame(0, (int) $report->commission_amount);
    }

    public function test_commission_report_page_shows_cb_and_wd_commission_as_separate_columns_plus_a_combined_total(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Commission Report Agent', 'code' => 'AG-COMM-REPORT', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $store = Merchant::query()->create(['slug' => 'commission-report-store', 'name' => 'Commission Report Store', 'agent_id' => $agent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'status' => 'active', 'apply_scope' => 'all']);
        $config->days()->create(['day_of_week' => 2, 'enabled' => true, 'rate_percent' => 0.1, 'wd_fee_fixed' => 250]);

        MerchantCommissionReport::query()->create([
            'merchant_id' => $store->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-10-20', 'day_of_week' => 2,
            'volume' => 1000000, 'rate_percent' => 0.1, 'commission_amount' => 1000,
            'wd_volume' => 500000, 'wd_trx_count' => 1, 'wd_fee_fixed' => 250, 'wd_commission_amount' => 250,
            'report_date' => '2026-10-21',
        ]);

        $response = $this->actingAs($ma)->get(route('ma.commission.report', ['month' => '2026-10']));

        $response->assertOk();
        $response->assertSee('Volume CB');
        $response->assertSee('Komisi CB');
        $response->assertSee('Volume WD');
        $response->assertSee('Komisi WD');
        $response->assertSee('Total Komisi');
        // Total = CB 1,000 + WD 250 = Rp 1.250.
        $response->assertSee('Rp 1.250');
    }

    public function test_ma_can_toggle_own_config_active_and_inactive_without_losing_history(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Commission Toggle Agent', 'code' => 'AG-COMM-TOGGLE', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'name' => 'Toggle Me', 'agent_id' => $agent->id, 'status' => 'active', 'apply_scope' => 'all']);

        $this->actingAs($ma)->patch(route('ma.commission.toggle-status', $config))->assertRedirect();
        $this->assertSame('inactive', $config->fresh()->status);

        $this->actingAs($ma)->patch(route('ma.commission.toggle-status', $config))->assertRedirect();
        $this->assertSame('active', $config->fresh()->status);
    }

    public function test_ma_cannot_toggle_another_mas_config(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $otherMa = User::query()->create(['name' => 'Other MA', 'email' => 'otherma-toggle@paygrid.local', 'username' => 'otherma-toggle', 'role' => 'ma', 'is_active' => true, 'password' => bcrypt('x'), 'plain_password' => 'x']);
        $agent = Agent::query()->create(['name' => 'Commission Toggle Other Agent', 'code' => 'AG-COMM-TOGGLE-OTHER', 'ma_user_id' => $otherMa->id, 'connection_type' => 'cm', 'is_active' => true]);
        $config = CommissionConfig::query()->create(['ma_user_id' => $otherMa->id, 'name' => 'Not Yours', 'agent_id' => $agent->id, 'status' => 'active', 'apply_scope' => 'all']);

        $this->actingAs($ma)->patch(route('ma.commission.toggle-status', $config))->assertForbidden();
        $this->assertSame('active', $config->fresh()->status);
    }

    public function test_deleting_a_config_removes_it_and_cascades_its_historical_report_rows(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Commission Delete Agent', 'code' => 'AG-COMM-DELETE', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $store = Merchant::query()->create(['slug' => 'commission-delete-store', 'name' => 'Commission Delete Store', 'agent_id' => $agent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'name' => 'Delete Me', 'agent_id' => $agent->id, 'status' => 'active', 'apply_scope' => 'all']);
        MerchantCommissionReport::query()->create([
            'merchant_id' => $store->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-10-20', 'day_of_week' => 2,
            'volume' => 1000000, 'rate_percent' => 0.1, 'commission_amount' => 1000,
            'report_date' => '2026-10-21',
        ]);

        $this->actingAs($ma)->delete(route('ma.commission.destroy', $config))->assertRedirect();

        $this->assertDatabaseMissing('commission_configs', ['id' => $config->id]);
        $this->assertSame(0, MerchantCommissionReport::query()->where('commission_config_id', $config->id)->count());
    }

    public function test_ma_cannot_delete_another_mas_config(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $otherMa = User::query()->create(['name' => 'Other MA 2', 'email' => 'otherma-delete@paygrid.local', 'username' => 'otherma-delete', 'role' => 'ma', 'is_active' => true, 'password' => bcrypt('x'), 'plain_password' => 'x']);
        $agent = Agent::query()->create(['name' => 'Commission Delete Other Agent', 'code' => 'AG-COMM-DELETE-OTHER', 'ma_user_id' => $otherMa->id, 'connection_type' => 'cm', 'is_active' => true]);
        $config = CommissionConfig::query()->create(['ma_user_id' => $otherMa->id, 'name' => 'Not Yours Either', 'agent_id' => $agent->id, 'status' => 'active', 'apply_scope' => 'all']);

        $this->actingAs($ma)->delete(route('ma.commission.destroy', $config))->assertForbidden();
        $this->assertDatabaseHas('commission_configs', ['id' => $config->id]);
    }

    public function test_commission_form_lists_configs_with_status_and_report_count(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Commission List Agent', 'code' => 'AG-COMM-LIST', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $store = Merchant::query()->create(['slug' => 'commission-list-store', 'name' => 'Commission List Store', 'agent_id' => $agent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'name' => 'Listed Config', 'agent_id' => $agent->id, 'status' => 'active', 'apply_scope' => 'specific']);
        $config->merchants()->sync([$store->id]);
        MerchantCommissionReport::query()->create([
            'merchant_id' => $store->id, 'commission_config_id' => $config->id,
            'transaction_date' => '2026-10-20', 'day_of_week' => 2,
            'volume' => 1000000, 'rate_percent' => 0.1, 'commission_amount' => 1000,
            'report_date' => '2026-10-21',
        ]);

        $response = $this->actingAs($ma)->get(route('ma.commission'));

        $response->assertOk();
        $response->assertSee('Listed Config');
        $response->assertSee('Commission List Store');
        $response->assertSee('1 baris');
    }
}
