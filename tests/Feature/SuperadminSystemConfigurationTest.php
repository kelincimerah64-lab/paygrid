<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\PaygridSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SuperadminSystemConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_access_the_page(): void
    {
        $this->seed();
        $ma = User::query()->where('role', 'ma')->firstOrFail();

        $this->actingAs($ma)->get(route('superadmin.page', 'system-configuration'))->assertForbidden();

        $superadmin = User::query()->where('role', 'superadmin')->firstOrFail();
        $this->actingAs($superadmin)->get(route('superadmin.page', 'system-configuration'))->assertOk();
    }

    public function test_saving_the_poll_interval_persists_and_is_read_back(): void
    {
        $this->seed();
        $superadmin = User::query()->where('role', 'superadmin')->firstOrFail();

        $response = $this->actingAs($superadmin)->post(route('superadmin.system-configuration.update'), [
            'gateway_sync_min_interval_seconds' => 30,
        ]);

        $response->assertRedirect();
        $this->assertSame('30', PaygridSetting::value('gateway_sync_min_interval_seconds', '5'));

        $page = $this->actingAs($superadmin)->get(route('superadmin.page', 'system-configuration'));
        $page->assertSee('30');
    }

    public function test_on_demand_reconcile_dispatches_jobs_for_the_requested_month(): void
    {
        $this->seed();
        $agent = Agent::query()->create(['name' => 'System Config Agent', 'code' => 'AG-SYSCONFIG', 'connection_type' => 'cm', 'is_active' => true]);
        Merchant::query()->create([
            'slug' => 'system-config-store', 'name' => 'System Config Store', 'agent_id' => $agent->id,
            'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved',
            'merchant_id' => 'store-sysconfig', 'merchant_key' => 'store-secret',
        ]);
        Queue::fake();
        $superadmin = User::query()->where('role', 'superadmin')->firstOrFail();

        $response = $this->actingAs($superadmin)->post(route('superadmin.system-configuration.reconcile'), [
            'month' => '2026-09',
        ]);

        $response->assertRedirect();
        Queue::assertPushed(\App\Jobs\ReconcileMerchantMonth::class, function ($job) {
            return $job->month === '2026-09';
        });
    }
}
