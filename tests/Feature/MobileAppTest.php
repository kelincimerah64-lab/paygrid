<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileAppTest extends TestCase
{
    use RefreshDatabase;

    private function makePilotMerchant(string $slug, string $password = 'secret123'): array
    {
        $npGroup = Agent::query()->firstOrCreate(['code' => 'AGN-702152'], ['name' => 'NP Group']);
        $merchant = Merchant::query()->create([
            'agent_id' => $npGroup->id,
            'slug' => $slug,
            'name' => 'np '.strtoupper($slug),
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'approval_status' => 'approved',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin '.$slug,
            'email' => $slug.'@paygrid.local',
            'role' => 'admin',
            'merchant_id' => $merchant->id,
            'password' => Hash::make($password),
            'plain_password' => $password,
        ]);

        return [$merchant, $admin];
    }

    public function test_login_form_only_lists_np_group_merchants(): void
    {
        [$npMerchant] = $this->makePilotMerchant('np-store');
        $otherAgent = Agent::query()->create(['code' => 'AGN-OTHER', 'name' => 'Other Group']);
        Merchant::query()->create([
            'agent_id' => $otherAgent->id,
            'slug' => 'other-store',
            'name' => 'Other Store',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'approval_status' => 'approved',
        ]);

        $response = $this->get(route('mobile.login'));

        $response->assertOk()->assertSee($npMerchant->name)->assertDontSee('Other Store');
    }

    public function test_correct_password_logs_in_and_reaches_dashboard(): void
    {
        [$merchant] = $this->makePilotMerchant('np-login-ok');

        $response = $this->post(route('mobile.login.attempt'), [
            'merchant_id' => $merchant->id,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('mobile.dashboard'));
        $this->get(route('mobile.dashboard'))->assertOk()->assertSee($merchant->name);
    }

    public function test_a_secondary_password_also_logs_in_without_changing_the_original(): void
    {
        [$merchant, $admin] = $this->makePilotMerchant('np-secondary-pw');
        $admin->setSecondaryPassword('backup-pass-1');

        $this->post(route('mobile.login.attempt'), [
            'merchant_id' => $merchant->id,
            'password' => 'backup-pass-1',
        ])->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());

        auth()->logout();

        $this->post(route('mobile.login.attempt'), [
            'merchant_id' => $merchant->id,
            'password' => 'secret123',
        ])->assertRedirect(route('mobile.dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_secondary_password_also_works_on_the_desktop_login(): void
    {
        [, $admin] = $this->makePilotMerchant('np-secondary-desktop');
        $admin->setSecondaryPassword('backup-pass-2');

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'backup-pass-2',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_wrong_password_is_rejected(): void
    {
        [$merchant] = $this->makePilotMerchant('np-login-bad');

        $response = $this->post(route('mobile.login.attempt'), [
            'merchant_id' => $merchant->id,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_dashboard_shows_only_this_merchants_own_data(): void
    {
        [$merchantA, $adminA] = $this->makePilotMerchant('np-scope-a');
        [$merchantB] = $this->makePilotMerchant('np-scope-b');

        TopupRequest::query()->create(['merchant_id' => $merchantA->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-a', 'status' => 'success', 'amount' => 50000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $merchantB->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-b', 'status' => 'success', 'amount' => 999000, 'submitted_at' => now()]);

        $response = $this->actingAs($adminA)->get(route('mobile.dashboard'));

        $response->assertOk()
            ->assertSee('Rp 50.000')
            ->assertDontSee('Rp 999.000');
    }

    public function test_ticket_count_is_scoped_to_the_merchant(): void
    {
        [$merchant, $admin] = $this->makePilotMerchant('np-tickets');
        SupportTicket::query()->create(['merchant_id' => $merchant->id, 'ticket_no' => 'TCK-1', 'status' => 'open']);
        SupportTicket::query()->create(['merchant_id' => $merchant->id, 'ticket_no' => 'TCK-2', 'status' => 'done']);

        $response = $this->actingAs($admin)->get(route('mobile.dashboard'));

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

    public function test_non_admin_role_cannot_reach_the_dashboard(): void
    {
        [$merchant] = $this->makePilotMerchant('np-role-guard');
        $cs = User::query()->create([
            'name' => 'CS User',
            'email' => 'cs-np-role-guard@paygrid.local',
            'role' => 'cs',
            'merchant_id' => $merchant->id,
            'password' => Hash::make('secret123'),
        ]);

        $this->actingAs($cs)->get(route('mobile.dashboard'))->assertForbidden();
    }
}
