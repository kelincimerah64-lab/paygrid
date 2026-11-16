<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingScriptFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function makeLink(): AgentOnboardingLink
    {
        $agent = Agent::query()->where('code', 'AG-EPC')->firstOrFail();

        return AgentOnboardingLink::query()->create([
            'agent_id' => $agent->id,
            'kind' => 'generic',
            'token' => Str::random(20),
            'status' => 'active',
            'reusable' => true,
        ]);
    }

    public function test_settlement_type_id_is_a_three_option_dropdown(): void
    {
        $this->seed();
        $link = $this->makeLink();

        $page = $this->get(route('merchant-registration.token-form', $link))->assertOk();
        $page->assertSee('name="settlement_type_id"', false);
        $page->assertSee('H+1');
        $page->assertSee('Everyday [ED]');
        $page->assertSee('Sameday [SD]');
    }

    public function test_script_type_rejects_submission_missing_callback_alpha_and_ip_fields(): void
    {
        $this->seed();
        $link = $this->makeLink();

        $response = $this->from(route('merchant-registration.token-form', $link))->post(route('merchant-registration.token-store', $link), [
            'store_name' => 'Script Missing Fields',
            'engine_type' => 'sc',
        ]);

        $response->assertSessionHasErrors(['callback_alpha_url', 'ip_pg', 'ip_alpha']);
        $this->assertDatabaseMissing('merchant_registrations', ['store_name' => 'Script Missing Fields']);
    }

    public function test_script_type_succeeds_with_required_fields_and_approve_persists_them(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $link = $this->makeLink();

        $submit = $this->post(route('merchant-registration.token-store', $link), [
            'store_name' => 'Script Complete Store',
            'admin_email' => 'script-complete-admin@paygrid-test.local',
            'engine_type' => 'sc',
            'callback_alpha_url' => 'https://alpha.example.com/callback',
            'ip_pg' => ['15.232.137.74', '', ''],
            'ip_alpha' => ['18.142.2.73', '13.229.39.57', ''],
        ]);
        $submit->assertRedirect();

        $registration = MerchantRegistration::query()->where('store_name', 'Script Complete Store')->firstOrFail();
        $this->assertSame('https://alpha.example.com/callback', $registration->payload['callback_alpha_url']);
        $this->assertSame('15.232.137.74', $registration->payload['ip_pg']);
        $this->assertSame('18.142.2.73, 13.229.39.57', $registration->payload['ip_alpha']);

        $approve = $this->actingAs($ma)->post(route('api.merchant-registration.approve', $registration), [
            'fee_menu_rates' => ['everyday' => 1.2],
            'admin_email' => 'script-complete-admin@paygrid-test.local',
            'engine_type' => 'sc',
        ]);
        $approve->assertRedirect();

        $merchant = Merchant::query()->where('name', 'Script Complete Store')->firstOrFail();
        $this->assertSame('https://alpha.example.com/callback', $merchant->callback_alpha_url);
        $this->assertSame(['15.232.137.74'], $merchant->ip_pg);
        $this->assertSame(['18.142.2.73', '13.229.39.57'], $merchant->ip_alpha);
    }

    public function test_api_type_does_not_require_script_only_fields(): void
    {
        $this->seed();
        $link = $this->makeLink();

        $response = $this->post(route('merchant-registration.token-store', $link), [
            'store_name' => 'Api Type Store',
            'admin_email' => 'api-type-admin@paygrid-test.local',
            'engine_type' => 'api',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('merchant_registrations', ['store_name' => 'Api Type Store']);
    }
}
