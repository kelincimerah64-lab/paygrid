<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantGatewayBalance;
use App\Models\MerchantWithdrawal;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchant(string $slug, string $role = 'admin', string $password = 'secret123'): array
    {
        $agent = Agent::query()->firstOrCreate(['code' => 'AGN-702152'], ['name' => 'NP Group']);
        $merchant = Merchant::query()->create([
            'agent_id' => $agent->id,
            'slug' => $slug,
            'name' => 'np '.strtoupper($slug),
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'approval_status' => 'approved',
        ]);
        $user = User::query()->create([
            'name' => ucfirst($role).' '.$slug,
            'email' => $slug.'-'.$role.'@paygrid.local',
            'role' => $role,
            'merchant_id' => $merchant->id,
            'password' => Hash::make($password),
            'plain_password' => $password,
        ]);

        return [$merchant, $user];
    }

    public function test_login_page_shows_a_single_email_password_form_with_no_store_picker(): void
    {
        [$merchant] = $this->makeMerchant('np-login-form');

        $response = $this->get(route('mobile.login'));

        $response->assertOk()
            ->assertSee('Email / Username')
            ->assertDontSee($merchant->name);
    }

    public function test_correct_password_logs_in_and_reaches_dashboard(): void
    {
        [$merchant, $admin] = $this->makeMerchant('np-login-ok');

        $response = $this->post(route('mobile.login.attempt'), [
            'email' => $admin->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('mobile.dashboard'));
        $this->get(route('mobile.dashboard'))->assertOk()->assertSee($merchant->name);
    }

    public function test_a_merchant_outside_np_group_can_still_log_in(): void
    {
        $otherAgent = Agent::query()->create(['code' => 'AGN-OTHER', 'name' => 'Other Group']);
        $merchant = Merchant::query()->create([
            'agent_id' => $otherAgent->id,
            'slug' => 'other-store',
            'name' => 'Other Store',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'approval_status' => 'approved',
        ]);
        $admin = User::query()->create([
            'name' => 'Other Store Admin',
            'email' => 'other-store-admin@paygrid.local',
            'role' => 'admin',
            'merchant_id' => $merchant->id,
            'password' => Hash::make('secret123'),
        ]);

        $this->post(route('mobile.login.attempt'), ['email' => $admin->email, 'password' => 'secret123'])
            ->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_secondary_password_also_logs_in_without_changing_the_original(): void
    {
        [, $admin] = $this->makeMerchant('np-secondary-pw');
        $admin->setSecondaryPassword('backup-pass-1');

        $this->post(route('mobile.login.attempt'), [
            'email' => $admin->email,
            'password' => 'backup-pass-1',
        ])->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());

        auth()->logout();

        $this->post(route('mobile.login.attempt'), [
            'email' => $admin->email,
            'password' => 'secret123',
        ])->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_secondary_password_also_works_on_the_desktop_login(): void
    {
        [, $admin] = $this->makeMerchant('np-secondary-desktop');
        $admin->setSecondaryPassword('backup-pass-2');

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'backup-pass-2',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_wrong_password_is_rejected(): void
    {
        [, $admin] = $this->makeMerchant('np-login-bad');

        $response = $this->post(route('mobile.login.attempt'), [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_dashboard_shows_only_this_merchants_own_data(): void
    {
        [$merchantA, $adminA] = $this->makeMerchant('np-scope-a');
        [$merchantB] = $this->makeMerchant('np-scope-b');

        TopupRequest::query()->create(['merchant_id' => $merchantA->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-a', 'status' => 'success', 'amount' => 50000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $merchantB->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-b', 'status' => 'success', 'amount' => 999000, 'submitted_at' => now()]);

        $response = $this->actingAs($adminA)->get(route('mobile.dashboard'));

        $response->assertOk()
            ->assertSee('Rp 50.000')
            ->assertDontSee('Rp 999.000');
    }

    public function test_ticket_count_is_scoped_to_the_merchant(): void
    {
        [$merchant, $admin] = $this->makeMerchant('np-tickets');
        SupportTicket::query()->create(['merchant_id' => $merchant->id, 'ticket_no' => 'TCK-1', 'status' => 'open']);
        SupportTicket::query()->create(['merchant_id' => $merchant->id, 'ticket_no' => 'TCK-2', 'status' => 'done']);

        $response = $this->actingAs($admin)->get(route('mobile.dashboard').'?tab=tiket');

        $response->assertOk();
        $response->assertViewHas('ticketStats', ['total' => 2, 'open' => 1, 'done' => 1]);
    }

    public function test_unauthenticated_mobile_request_redirects_to_mobile_login_not_desktop_login(): void
    {
        $this->get(route('mobile.dashboard'))->assertRedirect(route('mobile.login'));
    }

    public function test_unauthenticated_desktop_request_still_redirects_to_desktop_login(): void
    {
        $this->get('/ma')->assertRedirect(route('login'));
    }

    public function test_unsupported_role_cannot_reach_the_mobile_dashboard(): void
    {
        $readonlyCs = User::query()->create([
            'name' => 'Readonly CS',
            'email' => 'readonly-cs@paygrid.local',
            'role' => 'readonly_cs',
            'password' => Hash::make('secret123'),
        ]);

        $this->actingAs($readonlyCs)->get(route('mobile.dashboard'))->assertForbidden();
    }

    public function test_boss_role_sees_the_same_three_tabs_as_admin(): void
    {
        [$merchant, $boss] = $this->makeMerchant('np-boss', 'boss');
        TopupRequest::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-boss', 'status' => 'success', 'amount' => 75000, 'submitted_at' => now()]);

        $response = $this->actingAs($boss)->get(route('mobile.dashboard'));

        $response->assertOk()->assertViewHas('allowedTabs', ['trx', 'disbursement', 'tiket'])->assertSee('Rp 75.000');
    }

    public function test_finance_role_sees_keuangan_and_disbursement_tabs_with_saldo(): void
    {
        [$merchant, $finance] = $this->makeMerchant('np-finance', 'finance');
        MerchantGatewayBalance::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => 'hilogate',
            'active_balance' => 123456,
            'pending_balance' => 7890,
        ]);

        $response = $this->actingAs($finance)->get(route('mobile.dashboard'));

        $response->assertOk()
            ->assertViewHas('allowedTabs', ['keuangan', 'disbursement'])
            ->assertSee('Rp 123.456')
            ->assertSee('Rp 7.890');
    }

    public function test_cs_role_sees_only_tiket_and_disbursement_tabs_no_raw_trx(): void
    {
        [, $cs] = $this->makeMerchant('np-cs', 'cs');

        $response = $this->actingAs($cs)->get(route('mobile.dashboard'));

        $response->assertOk()->assertViewHas('allowedTabs', ['tiket', 'disbursement']);
        $this->assertSame('tiket', $response->viewData('tab'));
    }

    public function test_ma_logs_in_with_email_and_sees_only_merchants_under_their_own_agents(): void
    {
        $ma = User::query()->create([
            'name' => 'MA One',
            'email' => 'ma-one@paygrid.local',
            'role' => 'ma',
            'password' => Hash::make('secret123'),
        ]);
        $otherMa = User::query()->create([
            'name' => 'MA Two',
            'email' => 'ma-two@paygrid.local',
            'role' => 'ma',
            'password' => Hash::make('secret123'),
        ]);
        $myAgent = Agent::query()->create(['code' => 'AGN-MINE', 'name' => 'My Agent', 'ma_user_id' => $ma->id]);
        $otherAgent = Agent::query()->create(['code' => 'AGN-OTHER-MA', 'name' => 'Other Agent', 'ma_user_id' => $otherMa->id]);
        $myMerchant = Merchant::query()->create(['agent_id' => $myAgent->id, 'slug' => 'ma-mine', 'name' => 'Toko Punya MA Satu', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $otherMerchant = Merchant::query()->create(['agent_id' => $otherAgent->id, 'slug' => 'ma-other', 'name' => 'Toko Punya MA Lain', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        TopupRequest::query()->create(['merchant_id' => $myMerchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-mine', 'status' => 'success', 'amount' => 11000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $otherMerchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-other', 'status' => 'success', 'amount' => 22000, 'submitted_at' => now()]);

        $this->post(route('mobile.login.attempt'), ['email' => 'ma-one@paygrid.local', 'password' => 'secret123'])
            ->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($ma);

        $response = $this->get(route('mobile.dashboard').'?tab=trx');
        $response->assertOk()->assertSee('Rp 11.000')->assertDontSee('Rp 22.000');
        $this->assertSame(['trx', 'disbursement', 'tiket'], $response->viewData('allowedTabs'));
    }

    public function test_agent_logs_in_with_email_and_sees_only_their_own_merchants(): void
    {
        $agentUser = User::query()->create([
            'name' => 'Agent One',
            'email' => 'agent-one@paygrid.local',
            'username' => 'agent-one',
            'role' => 'agent',
            'password' => Hash::make('secret123'),
        ]);
        $agentRecord = Agent::query()->create(['code' => 'agent-one', 'name' => 'Agent One Co']);
        $myMerchant = Merchant::query()->create(['agent_id' => $agentRecord->id, 'slug' => 'agent-mine', 'name' => 'Toko Agent Satu', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $otherAgentRecord = Agent::query()->create(['code' => 'AGN-OTHER-AGENT', 'name' => 'Agent Two Co']);
        $otherMerchant = Merchant::query()->create(['agent_id' => $otherAgentRecord->id, 'slug' => 'agent-other', 'name' => 'Toko Agent Lain', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        TopupRequest::query()->create(['merchant_id' => $myMerchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-agent-mine', 'status' => 'success', 'amount' => 33000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $otherMerchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-agent-other', 'status' => 'success', 'amount' => 44000, 'submitted_at' => now()]);

        $this->post(route('mobile.login.attempt'), ['email' => 'agent-one@paygrid.local', 'password' => 'secret123'])
            ->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($agentUser);

        $response = $this->get(route('mobile.dashboard').'?tab=trx');
        $response->assertOk()->assertSee('Rp 33.000')->assertDontSee('Rp 44.000');
    }

    public function test_boss_role_can_also_reach_the_desktop_merchant_admin_page(): void
    {
        [$merchant, $boss] = $this->makeMerchant('np-boss-desktop', 'boss');

        $this->actingAs($boss)->get(route('merchant.admin.users', $merchant))->assertOk();
    }

    public function test_cs_role_cannot_see_transactions_by_forcing_the_tab_query_param(): void
    {
        [$merchant, $cs] = $this->makeMerchant('np-cs-url-guard', 'cs');
        TopupRequest::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-cs-guard', 'status' => 'success', 'amount' => 987654, 'submitted_at' => now()]);

        $response = $this->actingAs($cs)->get(route('mobile.dashboard').'?tab=trx');

        // falls back to the first allowed tab instead of honoring the forged tab
        $this->assertSame('tiket', $response->viewData('tab'));
        $response->assertOk()->assertDontSee('Rp 987.654')->assertDontSee('987654');
    }

    public function test_finance_role_cannot_see_tickets_by_forcing_the_tab_query_param(): void
    {
        [$merchant, $finance] = $this->makeMerchant('np-finance-url-guard', 'finance');
        SupportTicket::query()->create(['merchant_id' => $merchant->id, 'ticket_no' => 'TCK-SECRET-99', 'status' => 'open']);

        $response = $this->actingAs($finance)->get(route('mobile.dashboard').'?tab=tiket');

        $this->assertSame('keuangan', $response->viewData('tab'));
        $response->assertOk()->assertDontSee('TCK-SECRET-99');
    }

    public function test_ma_sees_disbursement_tab_scoped_to_their_own_portfolio(): void
    {
        $ma = User::query()->create(['name' => 'MA Guard', 'email' => 'ma-guard@paygrid.local', 'role' => 'ma', 'password' => Hash::make('secret123')]);
        $agent = Agent::query()->create(['code' => 'AGN-MA-GUARD', 'name' => 'Guard Agent', 'ma_user_id' => $ma->id]);
        $myMerchant = Merchant::query()->create(['agent_id' => $agent->id, 'slug' => 'ma-guard-store', 'name' => 'Toko MA Guard', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $otherAgent = Agent::query()->create(['code' => 'AGN-MA-GUARD-OTHER', 'name' => 'Other Agent']);
        $otherMerchant = Merchant::query()->create(['agent_id' => $otherAgent->id, 'slug' => 'ma-guard-other-store', 'name' => 'Toko Lain', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        MerchantWithdrawal::query()->create(['merchant_id' => $myMerchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'gw-wd-mine', 'ref_id' => 'wd-mine', 'status' => 'COMPLETED', 'amount' => 55000, 'net_amount' => 55000, 'gateway_created_at' => now()]);
        MerchantWithdrawal::query()->create(['merchant_id' => $otherMerchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'gw-wd-other', 'ref_id' => 'wd-other', 'status' => 'COMPLETED', 'amount' => 66000, 'net_amount' => 66000, 'gateway_created_at' => now()]);

        $response = $this->actingAs($ma)->get(route('mobile.dashboard').'?tab=disbursement');

        $this->assertSame('disbursement', $response->viewData('tab'));
        $response->assertOk()->assertSee('Rp 55.000')->assertDontSee('Rp 66.000');
    }

    public function test_agent_with_no_matching_agent_record_gets_404_not_a_crash(): void
    {
        $orphanAgentUser = User::query()->create([
            'name' => 'Orphan Agent',
            'email' => 'orphan-agent@paygrid.local',
            'username' => 'no-such-agent-code',
            'role' => 'agent',
            'password' => Hash::make('secret123'),
        ]);

        $this->actingAs($orphanAgentUser)->get(route('mobile.dashboard'))->assertNotFound();
    }

    public function test_ma_with_no_agents_sees_an_empty_but_working_dashboard(): void
    {
        $lonelyMa = User::query()->create(['name' => 'Lonely MA', 'email' => 'lonely-ma@paygrid.local', 'role' => 'ma', 'password' => Hash::make('secret123')]);

        $response = $this->actingAs($lonelyMa)->get(route('mobile.dashboard'));

        $response->assertOk()->assertViewHas('stats', ['success' => 0, 'success_amount' => 0, 'pending' => 0, 'pending_amount' => 0, 'expired' => 0, 'expired_amount' => 0]);
    }

    public function test_each_merchant_user_logs_in_with_their_own_email_when_a_merchant_has_multiple_role_accounts(): void
    {
        [$merchant, $admin] = $this->makeMerchant('np-multi-role', 'admin', 'admin-pass-1');
        $finance = User::query()->create([
            'name' => 'Finance np-multi-role',
            'email' => 'np-multi-role-finance@paygrid.local',
            'role' => 'finance',
            'merchant_id' => $merchant->id,
            'password' => Hash::make('finance-pass-2'),
        ]);

        $this->post(route('mobile.login.attempt'), ['email' => $finance->email, 'password' => 'finance-pass-2'])
            ->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($finance);

        auth()->logout();

        $this->post(route('mobile.login.attempt'), ['email' => $admin->email, 'password' => 'admin-pass-1'])
            ->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_inactive_boss_account_is_rejected(): void
    {
        [, $boss] = $this->makeMerchant('np-boss-inactive', 'boss');
        $boss->update(['is_active' => false]);

        $response = $this->post(route('mobile.login.attempt'), [
            'email' => $boss->email,
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_inactive_ma_account_is_rejected(): void
    {
        $ma = User::query()->create(['name' => 'Inactive MA', 'email' => 'inactive-ma@paygrid.local', 'role' => 'ma', 'password' => Hash::make('secret123'), 'is_active' => false]);

        $response = $this->post(route('mobile.login.attempt'), ['email' => 'inactive-ma@paygrid.local', 'password' => 'secret123']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_pagination_preserves_the_active_tab_for_a_portfolio_role(): void
    {
        $ma = User::query()->create(['name' => 'MA Pager', 'email' => 'ma-pager@paygrid.local', 'role' => 'ma', 'password' => Hash::make('secret123')]);
        $agent = Agent::query()->create(['code' => 'AGN-MA-PAGER', 'name' => 'Pager Agent', 'ma_user_id' => $ma->id]);
        $merchant = Merchant::query()->create(['agent_id' => $agent->id, 'slug' => 'ma-pager-store', 'name' => 'Toko MA Pager', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        for ($i = 0; $i < 25; $i++) {
            TopupRequest::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-page-'.$i, 'status' => 'success', 'amount' => 1000, 'submitted_at' => now()]);
        }

        $response = $this->actingAs($ma)->get(route('mobile.dashboard').'?tab=trx');
        $response->assertOk();
        $nextUrl = $response->viewData('transactions')->nextPageUrl();

        $this->assertStringContainsString('tab=trx', $nextUrl);
    }

    public function test_logout_works_for_a_portfolio_role_and_blocks_further_dashboard_access(): void
    {
        $ma = User::query()->create(['name' => 'MA Logout', 'email' => 'ma-logout@paygrid.local', 'role' => 'ma', 'password' => Hash::make('secret123')]);

        $this->actingAs($ma)->post(route('mobile.logout'))->assertRedirect(route('mobile.login'));
        $this->assertGuest();
        $this->get(route('mobile.dashboard'))->assertRedirect(route('mobile.login'));
    }
}
