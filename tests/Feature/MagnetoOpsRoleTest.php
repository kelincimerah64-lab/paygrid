<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\MerchantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MagnetoOpsRoleTest extends TestCase
{
    use RefreshDatabase;

    private function magnetoOpsUser(): User
    {
        return User::query()->create([
            'name' => 'Yesika',
            'email' => 'yesika@paygrid.local',
            'username' => 'yesika',
            'role' => 'magneto_ops',
            'is_active' => true,
            'password' => Hash::make('change-me'),
            'plain_password' => 'change-me',
        ]);
    }

    public function test_sidebar_only_has_the_two_magnetopay_menu_items(): void
    {
        $user = $this->magnetoOpsUser();

        $response = $this->actingAs($user)->get(route('ma.magnetopay-submissions'));

        $response->assertOk();
        $response->assertSee('Create Toko (Magneto)');
        $response->assertSee('Submission Magneto');
        $response->assertDontSee('Request Approval');
        $response->assertDontSee('Mapping Agen');
        $response->assertDontSee('Komisi Toko');
    }

    public function test_cannot_access_unrelated_ma_pages(): void
    {
        $user = $this->magnetoOpsUser();

        $this->actingAs($user)->get(route('ma.overview'))->assertForbidden();
        $this->actingAs($user)->get(route('ma.report'))->assertForbidden();
        $this->actingAs($user)->get(route('ma.approvals'))->assertForbidden();
        $this->actingAs($user)->get(route('ma.agents'))->assertForbidden();
    }

    public function test_sees_submissions_across_every_agent_not_scoped_to_one_ma(): void
    {
        $user = $this->magnetoOpsUser();
        $ma = User::query()->create(['name' => 'Other MA', 'email' => 'otherma@paygrid.local', 'username' => 'otherma', 'role' => 'ma', 'is_active' => true, 'password' => Hash::make('x'), 'plain_password' => 'x']);
        $agent = Agent::query()->create(['name' => 'Some Agent', 'code' => 'AG-MAGNETOOPS', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        MerchantRegistration::query()->create(['agent_id' => $agent->id, 'token' => Str::uuid(), 'store_name' => 'Owned By Other MA', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);

        $response = $this->actingAs($user)->get(route('ma.magnetopay-submissions'));

        $response->assertOk();
        $response->assertSee('Owned By Other MA');
    }

    public function test_can_reassign_a_submission_to_any_agent_and_save_secret_key(): void
    {
        $user = $this->magnetoOpsUser();
        $ma = User::query()->create(['name' => 'Other MA 2', 'email' => 'otherma2@paygrid.local', 'username' => 'otherma2', 'role' => 'ma', 'is_active' => true, 'password' => Hash::make('x'), 'plain_password' => 'x']);
        $agent = Agent::query()->create(['name' => 'Target Agent', 'code' => 'AG-MAGNETOOPS-TARGET', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $registration = MerchantRegistration::query()->create(['agent_id' => null, 'token' => Str::uuid(), 'store_name' => 'Reassign Target', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);

        $this->actingAs($user)->post(route('ma.magnetopay-submissions.update', $registration), [
            'agent_id' => $agent->id,
            'merchant_key' => 'secret-from-yesika',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $registration->refresh();
        $this->assertSame($agent->id, $registration->agent_id);
        $this->assertSame('secret-from-yesika', $registration->payload['merchant_key']);
    }

    public function test_post_login_redirect_lands_on_create_link_magneto(): void
    {
        $user = $this->magnetoOpsUser();

        $response = $this->post(route('login.store'), [
            'email' => 'yesika@paygrid.local',
            'password' => 'change-me',
        ]);

        $response->assertRedirect(route('ma.create-store-magnetopay'));
    }
}
