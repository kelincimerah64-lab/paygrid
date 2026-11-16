<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantCsChecklistDateFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transaction_submitted_early_morning_wib_shows_under_todays_wib_date_filter(): void
    {
        $this->seed();
        $user = User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();

        // 03:00 WIB today - stored as 20:00 UTC the previous calendar day.
        // submitted_at is UTC, so a naive whereDate() comparison against the
        // WIB "today" string misses this row entirely (it only shows up if
        // you filter to "yesterday" instead).
        $wibToday = CarbonImmutable::now('Asia/Jakarta');
        $earlyMorningWib = $wibToday->startOfDay()->addHours(3);
        $topup = TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'early-morning-ref',
            'transaction_id' => 'early-morning-trx',
            'status' => 'success',
            'amount' => 75000,
            'net_amount' => 74000,
            'fee_amount' => 1000,
            'customer_reference' => 'EARLY-MORNING-REF',
            'submitted_at' => $earlyMorningWib->utc(),
        ]);

        $todayWibDate = $wibToday->toDateString();

        $response = $this->actingAs($user)
            ->get(route('merchant.cs.checklist', $merchant).'?from='.$todayWibDate.'&to='.$todayWibDate)
            ->assertOk();

        $response->assertSee('EARLY-MORNING-REF');
    }
}
