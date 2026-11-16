<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\MerchantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalOnboardingLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeUniversalLink(): AgentOnboardingLink
    {
        return AgentOnboardingLink::query()->create([
            'agent_id' => null,
            'kind' => 'universal',
            'token' => 'universal-test-token',
            'status' => 'active',
            'reusable' => true,
        ]);
    }

    public function test_submitting_the_universal_link_creates_a_registration_with_no_agent_pending_ma_directly(): void
    {
        $this->seed();
        $link = $this->makeUniversalLink();

        $response = $this->post(route('merchant-registration.token-store', $link), [
            'store_name' => 'Universal Link Store',
            'admin_email' => 'universal-admin@paygrid-test.local',
        ]);
        $response->assertRedirect();

        $registration = MerchantRegistration::query()->where('store_name', 'Universal Link Store')->firstOrFail();
        $this->assertNull($registration->agent_id);
        $this->assertSame('pending_ma', $registration->status);
        $this->assertNotNull($registration->submitted_to_ma_at);

        // Reusable universal link stays active for the next submission.
        $this->assertSame('active', $link->fresh()->status);
    }

    public function test_universal_link_submission_is_visible_to_ma_and_notifies_them(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $link = $this->makeUniversalLink();

        $this->post(route('merchant-registration.token-store', $link), [
            'store_name' => 'Universal Visible Store',
            'admin_email' => 'universal-visible-admin@paygrid-test.local',
        ])->assertRedirect();

        $registration = MerchantRegistration::query()->where('store_name', 'Universal Visible Store')->firstOrFail();

        // Visible in the MA's own approval queue even though agent_id is null.
        $page = $this->actingAs($ma)->get(route('ma.approvals'))->assertOk();
        $page->assertSee('Universal Visible Store');

        $this->assertSame(1, $ma->fresh()->notifications()->where('data->registration_id', $registration->id)->count());
    }

    public function test_universal_link_registration_can_still_be_approved_by_ma(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $link = $this->makeUniversalLink();

        $this->post(route('merchant-registration.token-store', $link), [
            'store_name' => 'Universal Approve Store',
            'admin_email' => 'universal-approve-admin@paygrid-test.local',
        ])->assertRedirect();
        $registration = MerchantRegistration::query()->where('store_name', 'Universal Approve Store')->firstOrFail();

        $approve = $this->actingAs($ma)->post(route('api.merchant-registration.approve', $registration), [
            'fee_menu_rates' => ['everyday' => 1.2],
            'admin_email' => 'universal-approve-admin@paygrid-test.local',
            'ma_fee_percent' => '0.10',
            'agent_fee_percent' => '0.15',
        ]);
        $approve->assertRedirect();

        $merchant = \App\Models\Merchant::query()->where('name', 'Universal Approve Store')->firstOrFail();
        $this->assertSame('approved', $merchant->approval_status);
        $this->assertNull($merchant->agent_id);
        $this->assertSame(0.1, (float) $merchant->ma_fee_percent);
        $this->assertSame(0.15, (float) $merchant->agent_fee_percent);
    }
}
