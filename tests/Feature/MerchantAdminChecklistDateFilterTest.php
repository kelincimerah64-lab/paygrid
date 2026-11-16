<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantAdminChecklistDateFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transaction_submitted_early_morning_wib_shows_under_todays_wib_date_filter_in_admin_too(): void
    {
        // This had the exact same whereDate() UTC/WIB mismatch bug as the CS
        // checklist page (see MerchantCsChecklistDateFilterTest) - it just
        // hadn't been reported from the admin side yet.
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $admin = User::query()->where('merchant_id', $merchant->id)->where('role', 'admin')->first()
            ?? User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $wibToday = CarbonImmutable::now('Asia/Jakarta');
        $earlyMorningWib = $wibToday->startOfDay()->addHours(4);
        TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'admin-early-morning-ref',
            'transaction_id' => 'admin-early-morning-trx',
            'status' => 'success',
            'amount' => 88000,
            'net_amount' => 87000,
            'fee_amount' => 1000,
            'customer_reference' => 'ADMIN-EARLY-MORNING-REF',
            'submitted_at' => $earlyMorningWib->utc(),
        ]);

        $todayWibDate = $wibToday->toDateString();

        $response = $this->actingAs($admin)
            ->get(route('merchant.admin.checklist', $merchant).'?from='.$todayWibDate.'&to='.$todayWibDate)
            ->assertOk();

        $response->assertSee('ADMIN-EARLY-MORNING-REF');
    }
}
