<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reported case: a 10.000 test transaction made at 00:10 WIB on 27/10 showed in Gateway but not in the
 * store portal's "today", and appeared under 26/10 instead, because WIB filter dates were compared with
 * UTC timestamps.
 */
class WibDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private function topup(Merchant $merchant, string $submittedAtWib, int $amount): TopupRequest
    {
        $submittedAt = CarbonImmutable::parse($submittedAtWib, 'Asia/Jakarta')->utc();

        return TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'customer_reference' => 'PLAYER-'.Str::random(6),
            'idempotency_key' => (string) Str::uuid(),
            'public_token' => (string) Str::uuid(),
            'gateway' => $merchant->gateway,
            'data_source' => 'hilogate_pull',
            'gateway_ref_id' => 'qris_'.Str::random(10),
            'status' => 'success',
            'amount' => $amount,
            'net_amount' => $amount,
            'fee_amount' => 0,
            'submitted_at' => $submittedAt,
            'succeeded_at' => $submittedAt,
        ]);
    }

    public function test_a_transaction_just_after_wib_midnight_belongs_to_the_new_wib_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 00:20:00', 'Asia/Jakarta'));
        $merchant = Merchant::query()->create([
            'slug' => 'wib-date-test', 'name' => 'WIB Date Test', 'merchant_type' => 'cm', 'topup_enabled' => true,
            'gateway' => 'hilogate', 'merchant_id' => 'store-wib-date-test', 'approval_status' => 'approved',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin-wib-date@paygrid.local', 'role' => 'admin',
            'merchant_id' => $merchant->id, 'password' => 'secret',
        ]);

        $justAfterMidnight = $this->topup($merchant, '2026-10-27 00:10:00', 10000);
        $lateYesterday = $this->topup($merchant, '2026-10-26 23:50:00', 20000);

        // Default checklist view is "today" in WIB.
        $this->actingAs($admin)->get('/portal/wib-date-test/admin/checklist')
            ->assertOk()
            ->assertSee($justAfterMidnight->customer_reference)
            ->assertDontSee($lateYesterday->customer_reference);

        $this->actingAs($admin)->get('/portal/wib-date-test/admin/checklist?from=2026-10-26&to=2026-10-26')
            ->assertOk()
            ->assertSee($lateYesterday->customer_reference)
            ->assertDontSee($justAfterMidnight->customer_reference);
    }

    public function test_the_helper_turns_wib_dates_into_utc_bounds(): void
    {
        $sql = TopupRequest::query()->whereWibDateBetween('submitted_at', '2026-10-27', '2026-10-27')->toRawSql();

        $this->assertStringContainsString("'2026-10-26 17:00:00'", $sql);
        $this->assertStringContainsString("'2026-10-27 16:59:59'", $sql);
        $this->assertStringNotContainsString('submitted_at" <=', TopupRequest::query()->whereWibDateBetween('submitted_at', '2026-10-27', null)->toRawSql());
    }
}
