<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaCreateStoreMagnetopayTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_the_form_creates_a_magnetopay_merchant_with_the_new_fields_and_a_default_admin(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->create(['name' => 'Magneto Agent', 'code' => 'AG-MAGNETO', 'ma_user_id' => $ma->id, 'connection_type' => 'cm', 'is_active' => true]);

        $response = $this->actingAs($ma)->post(route('ma.create-store-magnetopay.store'), [
            'name' => 'nm HHT | IDN',
            'agent_id' => $agent->id,
            'admin_email' => ['hht-admin@paygrid-test.local'],
            'merchant_id' => '515063ad-134f-41a1-a506-7dd26346bafc',
            'merchant_key' => 'super-secret',
            'merchant_group_name' => 'NP x Nemo Group (Everyday)',
            'va_callback_url' => 'https://alph4-4z.com/hilo-va-callback',
            'qris_settlement_type' => 'Everyday',
            'va_settlement_type' => 'H+1',
            'ip_whitelist_enabled' => '1',
            'dashboard_ip_whitelist' => ['15.232.137.74', '18.142.2.73'],
            'api_ip_whitelist' => ['15.232.137.74', '18.142.2.73', '13.229.39.57'],
            'transaction_payment_gateway' => 'JAYA DIGITAL RTS',
            'withdrawal_payment_gateway' => 'INA WD',
            'magnetopay_fee_1_percent' => 0.5,
            'magnetopay_fee_withdrawal_fixed' => -499,
        ]);

        $response->assertRedirect();
        $merchant = Merchant::query()->where('name', 'nm HHT | IDN')->firstOrFail();
        $this->assertSame('magnetopay', $merchant->gateway);
        $this->assertSame('cm', $merchant->merchant_type);
        $this->assertSame('approved', $merchant->approval_status);
        $this->assertSame('515063ad-134f-41a1-a506-7dd26346bafc', $merchant->merchant_id);
        $this->assertSame('super-secret', $merchant->merchant_key);
        $this->assertSame('NP x Nemo Group (Everyday)', $merchant->merchant_group_name);
        $this->assertSame('https://alph4-4z.com/hilo-va-callback', $merchant->va_callback_url);
        $this->assertSame('Everyday', $merchant->qris_settlement_type);
        $this->assertSame('H+1', $merchant->va_settlement_type);
        $this->assertTrue($merchant->ip_whitelist_enabled);
        $this->assertSame(['15.232.137.74', '18.142.2.73'], $merchant->dashboard_ip_whitelist);
        $this->assertSame(['15.232.137.74', '18.142.2.73', '13.229.39.57'], $merchant->api_ip_whitelist);
        $this->assertSame(0.5, (float) $merchant->magnetopay_fee_1_percent);
        $this->assertSame(-499, $merchant->magnetopay_fee_withdrawal_fixed);
        $this->assertSame('JAYA DIGITAL RTS', $merchant->onboarding_payload['transaction_payment_gateway']);

        $this->assertDatabaseHas('users', ['email' => 'hht-admin@paygrid-test.local', 'role' => 'admin', 'merchant_id' => $merchant->id]);
    }

    public function test_an_agent_outside_the_mas_own_agents_is_rejected(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $otherAgent = Agent::query()->create(['name' => 'Someone Elses Agent', 'code' => 'AG-MAGNETO-OTHER', 'connection_type' => 'cm', 'is_active' => true]);

        $response = $this->actingAs($ma)->post(route('ma.create-store-magnetopay.store'), [
            'name' => 'Should Not Be Created',
            'agent_id' => $otherAgent->id,
            'admin_email' => ['blocked-admin@paygrid-test.local'],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('merchants', ['name' => 'Should Not Be Created']);
    }
}
