<?php

namespace Tests\Feature;

use App\Jobs\BackfillCommissionReportsThisMonth;
use App\Models\Agent;
use App\Models\CommissionConfig;
use App\Models\Merchant;
use App\Models\MerchantCommissionReport;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillCommissionReportsThisMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_every_elapsed_day_this_month_for_an_enabled_weekday(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-11 10:00:00', 'Asia/Jakarta'));

        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Backfill Agent', 'code' => 'AG-BACKFILL', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $store = Merchant::query()->create(['slug' => 'backfill-store', 'name' => 'Backfill Store', 'agent_id' => $agent->id, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);

        // 2026-11-02 is a Monday (day_of_week = 1).
        $this->assertSame(1, \Carbon\CarbonImmutable::parse('2026-11-02', 'Asia/Jakarta')->dayOfWeek);

        $config = CommissionConfig::query()->create(['ma_user_id' => $ma->id, 'name' => 'Backfill Config', 'agent_id' => $agent->id, 'status' => 'active', 'apply_scope' => 'all']);
        for ($d = 0; $d <= 6; $d++) {
            $config->days()->create(['day_of_week' => $d, 'enabled' => $d === 1, 'rate_percent' => 0.5]);
        }

        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'backfill-a', 'transaction_id' => 'backfill-a',
            'status' => 'success', 'amount' => 1000000, 'net_amount' => 989000, 'fee_amount' => 11000,
            'customer_reference' => 'REF-BACKFILL-A',
            'submitted_at' => \Carbon\CarbonImmutable::parse('2026-11-02', 'Asia/Jakarta')->setTime(10, 0)->utc(),
        ]);

        (new BackfillCommissionReportsThisMonth())->handle();

        $report = MerchantCommissionReport::query()->where('merchant_id', $store->id)->where('transaction_date', '2026-11-02')->first();
        $this->assertNotNull($report, 'Monday 2026-11-02 should have been backfilled even though "today" is 2026-11-11.');
        $this->assertSame(1000000, (int) $report->volume);
        $this->assertSame(5000, (int) $report->commission_amount);

        // "Today" (2026-11-11) itself must not be processed - only days strictly before today.
        $this->assertSame(0, MerchantCommissionReport::query()->where('transaction_date', '2026-11-11')->count());
    }

    public function test_it_does_nothing_on_the_first_day_of_the_month(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 10:00:00', 'Asia/Jakarta'));
        $this->seed();

        (new BackfillCommissionReportsThisMonth())->handle();

        $this->assertSame(0, MerchantCommissionReport::query()->count());
    }
}
