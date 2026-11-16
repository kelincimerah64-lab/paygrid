<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaMagnetopaySubmissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_this_mas_submissions_and_unassigned_ones_are_visible(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();

        $ownAgent = Agent::query()->create(['name' => 'Magneto Own Agent', 'code' => 'AG-MAGNETO-OWN', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $otherAgent = Agent::query()->create(['name' => 'Magneto Other Agent', 'code' => 'AG-MAGNETO-OTHER', 'connection_type' => 'cm', 'is_active' => true]);

        $own = MerchantRegistration::query()->create(['agent_id' => $ownAgent->id, 'token' => Str::uuid(), 'store_name' => 'Magneto Own Store', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);
        $unassigned = MerchantRegistration::query()->create(['agent_id' => null, 'token' => Str::uuid(), 'store_name' => 'Magneto Unassigned Store', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);
        $other = MerchantRegistration::query()->create(['agent_id' => $otherAgent->id, 'token' => Str::uuid(), 'store_name' => 'Magneto Other Store', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);
        $nonMagneto = MerchantRegistration::query()->create(['agent_id' => $ownAgent->id, 'token' => Str::uuid(), 'store_name' => 'Hilogate Store', 'gateway' => 'hilogate', 'status' => 'pending_ma']);

        $response = $this->actingAs($ma)->get(route('ma.magnetopay-submissions'));

        $response->assertOk();
        $response->assertSee('Magneto Own Store');
        $response->assertSee('Magneto Unassigned Store');
        $response->assertDontSee('Magneto Other Store');
        $response->assertDontSee('Hilogate Store');
    }

    public function test_reassigning_agent_requires_an_agent_owned_by_this_ma(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $ownAgent = Agent::query()->create(['name' => 'Magneto Reassign Agent', 'code' => 'AG-MAGNETO-REASSIGN', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $otherAgent = Agent::query()->create(['name' => 'Magneto Reassign Other', 'code' => 'AG-MAGNETO-REASSIGN-OTHER', 'connection_type' => 'cm', 'is_active' => true]);

        $registration = MerchantRegistration::query()->create(['agent_id' => null, 'token' => Str::uuid(), 'store_name' => 'Magneto Reassign Store', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);

        $this->actingAs($ma)->post(route('ma.magnetopay-submissions.update', $registration), ['agent_id' => $otherAgent->id])
            ->assertInvalid(['agent_id']);

        $this->actingAs($ma)->post(route('ma.magnetopay-submissions.update', $registration), ['agent_id' => $ownAgent->id])
            ->assertRedirect();

        $this->assertSame($ownAgent->id, $registration->fresh()->agent_id);
    }

    public function test_secret_key_before_approval_only_updates_the_payload(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Pending Agent', 'code' => 'AG-MAGNETO-PENDING', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $registration = MerchantRegistration::query()->create(['agent_id' => $agent->id, 'token' => Str::uuid(), 'store_name' => 'Magneto Pending Store', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);

        $this->actingAs($ma)->post(route('ma.magnetopay-submissions.update', $registration), [
            'agent_id' => $agent->id,
            'merchant_key' => 'secret-before-approval',
        ])->assertRedirect();

        $registration->refresh();
        $this->assertSame('secret-before-approval', $registration->payload['merchant_key']);
        $this->assertNull($registration->merchant_id);
    }

    public function test_gateway_merchant_id_before_approval_only_updates_the_payload(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Pending Agent 2', 'code' => 'AG-MAGNETO-PENDING-2', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $registration = MerchantRegistration::query()->create(['agent_id' => $agent->id, 'token' => Str::uuid(), 'store_name' => 'Magneto Pending Store 2', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);

        $this->actingAs($ma)->post(route('ma.magnetopay-submissions.update', $registration), [
            'agent_id' => $agent->id,
            'gateway_merchant_id' => 'mid-before-approval',
        ])->assertRedirect();

        $registration->refresh();
        $this->assertSame('mid-before-approval', $registration->payload['merchant_id']);
        $this->assertNull($registration->merchant_id);
    }

    public function test_secret_key_after_approval_updates_the_live_merchant_directly(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Approved Agent', 'code' => 'AG-MAGNETO-APPROVED', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $otherAgent = Agent::query()->create(['name' => 'Magneto Approved Agent 2', 'code' => 'AG-MAGNETO-APPROVED-2', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $merchant = Merchant::query()->create(['slug' => 'magneto-approved-store', 'name' => 'Magneto Approved Store', 'agent_id' => $agent->id, 'merchant_type' => 'cm', 'gateway' => 'magnetopay', 'approval_status' => 'approved', 'merchant_id' => 'store-magneto-approved']);
        $registration = MerchantRegistration::query()->create(['agent_id' => $agent->id, 'merchant_id' => $merchant->id, 'token' => Str::uuid(), 'store_name' => 'Magneto Approved Store', 'gateway' => 'magnetopay', 'status' => 'approved']);

        $this->actingAs($ma)->post(route('ma.magnetopay-submissions.update', $registration), [
            'agent_id' => $otherAgent->id,
            'merchant_key' => 'secret-after-approval',
            'gateway_merchant_id' => 'mid-after-approval',
        ])->assertRedirect();

        $merchant->refresh();
        $this->assertSame('secret-after-approval', $merchant->merchant_key);
        $this->assertSame('mid-after-approval', $merchant->merchant_id);
        $this->assertSame($otherAgent->id, $merchant->agent_id);
    }

    public function test_superadmin_can_reassign_to_any_agent_regardless_of_ma(): void
    {
        $this->seed();
        $superadmin = User::query()->where('role', 'superadmin')->firstOrFail();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Superadmin Agent', 'code' => 'AG-MAGNETO-SUPERADMIN', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);
        $registration = MerchantRegistration::query()->create(['agent_id' => null, 'token' => Str::uuid(), 'store_name' => 'Magneto Superadmin Store', 'gateway' => 'magnetopay', 'status' => 'pending_ma']);

        $this->actingAs($superadmin)->post(route('ma.magnetopay-submissions.update', $registration), [
            'agent_id' => $agent->id,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertSame($agent->id, $registration->fresh()->agent_id);
    }
}
