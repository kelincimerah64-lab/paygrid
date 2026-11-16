<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaMagnetopayOnboardingLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_ma_generates_a_magnetopay_link_distinct_from_the_generic_one_and_submitting_it_then_approving_creates_a_merchant(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Link Agent', 'code' => 'AG-MAGNETO-LINK', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);

        $generate = $this->actingAs($ma)->post(route('ma.onboarding-links.magnetopay.store'), [
            'agent_id' => $agent->id,
            'fee_menu_rates' => ['everyday' => 1.2],
            'disbursement_fee_percent' => '0.15',
            'disbursement_fee_fixed' => '1900',
        ]);
        $generate->assertRedirect();

        $link = AgentOnboardingLink::query()->where('agent_id', $agent->id)->where('kind', 'magnetopay')->firstOrFail();
        $this->assertTrue($link->isUsable());

        // Settlement Type + MDR, and who generated it for which agent, are now
        // captured at generation time - every link (submitted or not) gets a
        // human-readable Form ID too.
        $this->assertSame('everyday', $link->fee_menu);
        $this->assertSame(1.2, (float) $link->fee_menu_rates['everyday']);
        $this->assertSame($ma->id, $link->created_by_user_id);
        $this->assertMatchesRegularExpression('/^REG-\d{6}$/', $link->form_number);
        $this->assertSame(0.15, (float) $link->disbursement_fee_percent);
        $this->assertSame(1900, $link->disbursement_fee_fixed);

        // Generating a regular link for the same agent must not collide with the magnetopay one.
        $this->actingAs($ma)->post(route('ma.onboarding-links.store'), ['agent_id' => $agent->id])->assertRedirect();
        $genericLink = AgentOnboardingLink::query()->where('agent_id', $agent->id)->where('kind', 'generic')->firstOrFail();
        $this->assertNotSame($link->id, $genericLink->id);
        $this->assertTrue($link->fresh()->isUsable(), 'generating the generic link must not expire the magnetopay one');

        // Settlement/fee fields are MA-only - even if a submitter posts them
        // directly (bypassing the form, which no longer renders these
        // inputs), the public endpoint must strip them. IP whitelist is now
        // allowed through from the public submitter - the MA still
        // reviews/edits it at approval time.
        $submit = $this->post(route('merchant-registration.magnetopay.token-store', $link), [
            'store_name' => 'Magneto Link Store',
            'admin_email' => 'magneto-link-admin@paygrid-test.local',
            'va_callback_url' => 'https://alph4-4z.com/hilo-va-callback',
            'qris_settlement_type' => 'Everyday',
            'va_settlement_type' => 'H+1',
            'is_whitelisted' => '1',
            'ip_dashboard' => ['15.232.137.74', '18.142.2.73', '', '', ''],
            'api_ip_whitelist' => ['15.232.137.74', '18.142.2.73', '13.229.39.57', '', ''],
            'engine_type' => 'sc',
            'engine_base_url' => 'https://engine.example.com',
            'engine_token_api' => 'engine-token-abc',
            'engine_agent_back_panel' => 'https://backpanel.example.com',
            'ip_alpha' => ['45.10.20.30', '45.10.20.31', ''],
            'magnetopay_fee_1_percent' => '0.5',
            'magnetopay_fee_va_percent' => '0.1',
            'magnetopay_fee_withdrawal_fixed' => '-499',
            'pic_credentials' => [
                ['name' => 'Budi', 'contact' => '@budi', 'email' => 'budi@paygrid-test.local'],
                ['name' => '', 'contact' => '', 'email' => ''],
                ['name' => 'Citra', 'contact' => '', 'email' => 'citra@paygrid-test.local'],
            ],
            'finance_role_email' => ['finance1@paygrid-test.local', 'finance2@paygrid-test.local', ''],
            'bank_account' => [
                ['name' => 'PT Magneto Link Store', 'number' => '1234567890', 'bank' => 'BCA'],
                ['name' => '', 'number' => '', 'bank' => ''],
                ['name' => '', 'number' => '', 'bank' => ''],
                ['name' => '', 'number' => '', 'bank' => ''],
                ['name' => '', 'number' => '', 'bank' => ''],
            ],
        ]);
        $submit->assertRedirect();

        $registration = MerchantRegistration::query()->where('store_name', 'Magneto Link Store')->firstOrFail();
        $this->assertSame('magnetopay', $registration->gateway);
        $this->assertSame('https://alph4-4z.com/hilo-va-callback', $registration->payload['va_callback_url']);
        $this->assertSame('Everyday', $registration->payload['qris_settlement_type']);
        $this->assertSame('15.232.137.74, 18.142.2.73', $registration->payload['ip_dashboard']);
        $this->assertSame('15.232.137.74, 18.142.2.73, 13.229.39.57', $registration->payload['api_ip_whitelist']);
        $this->assertSame('sc', $registration->payload['engine_type']);
        $this->assertSame('https://engine.example.com', $registration->payload['engine_base_url']);
        $this->assertSame('engine-token-abc', $registration->payload['engine_token_api']);
        $this->assertSame('https://backpanel.example.com', $registration->payload['engine_agent_back_panel']);
        $this->assertSame('45.10.20.30, 45.10.20.31', $registration->payload['ip_alpha']);
        $this->assertArrayNotHasKey('va_settlement_type', $registration->payload);
        $this->assertArrayNotHasKey('is_whitelisted', $registration->payload);
        $this->assertArrayNotHasKey('magnetopay_fee_1_percent', $registration->payload);

        // CRF-absorbed fields: PIC Kredensial and Rekening Bank are fixed-row
        // groups stashed as nested arrays (not flattened like the single-value
        // multi-fields); Finance Role mirrors cs_email's comma-join convention.
        $this->assertSame('Budi', $registration->payload['pic_credentials'][0]['name']);
        $this->assertSame('citra@paygrid-test.local', $registration->payload['pic_credentials'][2]['email']);
        $this->assertSame('finance1@paygrid-test.local, finance2@paygrid-test.local', $registration->payload['finance_role_email']);
        $this->assertSame('BCA', $registration->payload['bank_account'][0]['bank']);
        $this->assertSame(
            'Budi / @budi / budi@paygrid-test.local; Citra / citra@paygrid-test.local',
            $registration->detailRows()['PIC untuk Kredensial'],
        );
        $this->assertSame('finance1@paygrid-test.local, finance2@paygrid-test.local', $registration->detailRows()['Email Finance Role']);
        $this->assertSame(
            'PT Magneto Link Store / 1234567890 / BCA',
            $registration->detailRows()['Whitelist Rekening Bank'],
        );

        // "Engine Selection" = Script now sets merchant_type=script on the registration
        // (see MerchantRegistrationController::tokenStoreMagnetopay), which in turn
        // makes engine_type a required field on the approval form - same business
        // rule the generic/Hilogate script-type flow already enforces.
        $this->assertSame('script', $registration->merchant_type);

        // The submitter's IP is captured, and the originating link (with its
        // own Form ID and the Settlement/MDR chosen at generation time) is
        // resolvable from the submission even though the link is reusable.
        $this->assertNotNull($registration->ip_address);
        $this->assertSame($link->id, $registration->onboardingLink()?->id);
        $this->assertSame($link->form_number, $registration->onboardingLink()?->form_number);

        // MA still reviews/edits the submitted whitelist and fills in settlement/fee details at approval time.
        $approve = $this->actingAs($ma)->post(route('api.merchant-registration.approve', $registration), [
            'fee_menu_rates' => ['everyday' => 1.2],
            'admin_email' => 'magneto-link-admin@paygrid-test.local',
            'engine_type' => 'sc',
            'va_settlement_type' => 'H+1',
            'is_whitelisted' => '1',
            'ip_dashboard' => '15.232.137.74, 18.142.2.73',
            'api_ip_whitelist' => '15.232.137.74, 18.142.2.73, 13.229.39.57',
            'magnetopay_fee_1_percent' => '0.5',
            'magnetopay_fee_va_percent' => '0.1',
            'magnetopay_fee_withdrawal_fixed' => '-499',
        ]);
        $approve->assertRedirect();

        $merchant = Merchant::query()->where('name', 'Magneto Link Store')->firstOrFail();
        $this->assertSame('magnetopay', $merchant->gateway);
        $this->assertSame('https://alph4-4z.com/hilo-va-callback', $merchant->va_callback_url);
        $this->assertSame('Everyday', $merchant->qris_settlement_type);
        $this->assertSame('H+1', $merchant->va_settlement_type);
        $this->assertTrue($merchant->ip_whitelist_enabled);
        $this->assertSame(['15.232.137.74', '18.142.2.73'], $merchant->dashboard_ip_whitelist);
        $this->assertSame(['15.232.137.74', '18.142.2.73', '13.229.39.57'], $merchant->api_ip_whitelist);
        $this->assertSame(0.5, (float) $merchant->magnetopay_fee_1_percent);
        $this->assertSame(0.1, (float) $merchant->magnetopay_fee_va_percent);
        $this->assertSame(-499, $merchant->magnetopay_fee_withdrawal_fixed);

        // Reusable link stays active for the next submission.
        $this->assertSame('active', $link->fresh()->status);
    }

    public function test_magnetopay_onboarding_page_shows_the_generated_link(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Page Agent', 'code' => 'AG-MAGNETO-PAGE', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);

        $this->actingAs($ma)->post(route('ma.onboarding-links.magnetopay.store'), ['agent_id' => $agent->id, 'fee_menu_rates' => ['everyday' => 1.2]])->assertRedirect();

        $response = $this->actingAs($ma)->get(route('ma.create-store-magnetopay'))->assertOk();
        $response->assertSee('Generate Link Onboarding (Magneto)');
        $response->assertSee('Magneto Page Agent');
    }

    public function test_public_magnetopay_form_exposes_ip_whitelist_but_not_settlement_or_fee_fields(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Hidden Agent', 'code' => 'AG-MAGNETO-HIDDEN', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $this->actingAs($ma)->post(route('ma.onboarding-links.magnetopay.store'), ['agent_id' => $agent->id, 'fee_menu_rates' => ['everyday' => 1.2]])->assertRedirect();
        $link = AgentOnboardingLink::query()->where('agent_id', $agent->id)->where('kind', 'magnetopay')->firstOrFail();

        $page = $this->get(route('merchant-registration.magnetopay.token-form', $link))->assertOk();
        $page->assertSee('QRIS Settlement Type');
        $page->assertSee('data-multi-field="api_ip_whitelist"', false);
        $page->assertSee('data-multi-field="ip_dashboard"', false);
        $page->assertSee('id="engine-type-select"', false);
        $page->assertSee('data-multi-field="ip_alpha"', false);
        $page->assertDontSee('VA Settlement Type', false);
        $page->assertDontSee('name="is_whitelisted"', false);
        $page->assertDontSee('name="magnetopay_fee_1_percent"', false);
        $page->assertDontSee('Merchant Payment Setup', false);
    }

    public function test_engine_selection_cm_sets_merchant_type_cm_and_strips_engine_type(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto CM Agent', 'code' => 'AG-MAGNETO-CM', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $this->actingAs($ma)->post(route('ma.onboarding-links.magnetopay.store'), ['agent_id' => $agent->id, 'fee_menu_rates' => ['everyday' => 1.2]])->assertRedirect();
        $link = AgentOnboardingLink::query()->where('agent_id', $agent->id)->where('kind', 'magnetopay')->firstOrFail();

        $this->post(route('merchant-registration.magnetopay.token-store', $link), [
            'store_name' => 'Magneto CM Store',
            'admin_email' => 'cm-admin@paygrid-test.local',
            'engine_type' => 'cm',
        ])->assertRedirect();

        $registration = MerchantRegistration::query()->where('store_name', 'Magneto CM Store')->firstOrFail();
        $this->assertSame('cm', $registration->merchant_type);
        $this->assertArrayNotHasKey('engine_type', $registration->payload);
    }

    public function test_engine_selection_api_sets_merchant_type_script(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto API Agent', 'code' => 'AG-MAGNETO-API', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $this->actingAs($ma)->post(route('ma.onboarding-links.magnetopay.store'), ['agent_id' => $agent->id, 'fee_menu_rates' => ['everyday' => 1.2]])->assertRedirect();
        $link = AgentOnboardingLink::query()->where('agent_id', $agent->id)->where('kind', 'magnetopay')->firstOrFail();

        $this->post(route('merchant-registration.magnetopay.token-store', $link), [
            'store_name' => 'Magneto API Store',
            'admin_email' => 'api-admin@paygrid-test.local',
            'engine_type' => 'api',
        ])->assertRedirect();

        $registration = MerchantRegistration::query()->where('store_name', 'Magneto API Store')->firstOrFail();
        $this->assertSame('script', $registration->merchant_type);
        $this->assertSame('api', $registration->payload['engine_type']);
    }

    public function test_approval_form_prefills_fee_menu_from_the_originating_link(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Prefill Agent', 'code' => 'AG-MAGNETO-PREFILL', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $this->actingAs($ma)->post(route('ma.onboarding-links.magnetopay.store'), [
            'agent_id' => $agent->id,
            'fee_menu_rates' => ['everyday_sc' => 1.3],
            'disbursement_fee_percent' => '0.25',
            'disbursement_fee_fixed' => '2200',
        ])->assertRedirect();
        $link = AgentOnboardingLink::query()->where('agent_id', $agent->id)->where('kind', 'magnetopay')->firstOrFail();

        $this->post(route('merchant-registration.magnetopay.token-store', $link), [
            'store_name' => 'Magneto Prefill Store',
            'admin_email' => 'prefill-admin@paygrid-test.local',
            'engine_type' => 'sc',
        ])->assertRedirect();

        // Nothing has been approved yet, so the approval form's fee-menu rate
        // and disbursement fee inputs should default to the values chosen on
        // the link at generation time (prefill, not locked - the MA can still
        // type different ones).
        $page = $this->actingAs($ma)->get(route('ma.approvals'))->assertOk();
        $content = $page->getContent();
        $this->assertMatchesRegularExpression(
            '/name="fee_menu_rates\[everyday_sc\]"\s+value="1\.3"/',
            $content,
        );
        $this->assertStringContainsString('name="disbursement_fee_percent" value="0.25"', $content);
        $this->assertStringContainsString('name="disbursement_fee_fixed" value="2200"', $content);
    }
}
