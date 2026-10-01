<?php

namespace Tests\Unit;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\CsScopeResolver;
use App\Services\MaAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaAssistantServiceScopingTest extends TestCase
{
    use RefreshDatabase;

    private function invokeTool(MaAssistantService $service, string $method, array $args): array
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return json_decode(json_encode($reflection->invokeArgs($service, $args)), true);
    }

    public function test_list_merchants_never_returns_another_mas_stores(): void
    {
        $maA = User::query()->create(['name' => 'MA A', 'email' => 'ma-a-assistant@paygrid.local', 'role' => 'ma', 'password' => 'secret']);
        $maB = User::query()->create(['name' => 'MA B', 'email' => 'ma-b-assistant@paygrid.local', 'role' => 'ma', 'password' => 'secret']);

        $agentA = Agent::query()->create(['ma_user_id' => $maA->id, 'code' => 'AG-A', 'name' => 'Agent A']);
        $agentB = Agent::query()->create(['ma_user_id' => $maB->id, 'code' => 'AG-B', 'name' => 'Agent B']);

        Merchant::query()->create(['slug' => 'store-a-secret', 'name' => 'Store A Secret', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'agent_id' => $agentA->id, 'approval_status' => 'approved']);
        Merchant::query()->create(['slug' => 'store-b-secret', 'name' => 'Store B Secret', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'agent_id' => $agentB->id, 'approval_status' => 'approved']);

        $service = new MaAssistantService(new CsScopeResolver);

        $resultForA = $this->invokeTool($service, 'toolListMerchants', [$maA, []]);
        $names = array_column($resultForA['merchants'], 'name');

        $this->assertContains('Store A Secret', $names);
        $this->assertNotContains('Store B Secret', $names, "MA A's assistant tool must never see MA B's store.");
    }

    public function test_merchant_detail_refuses_a_store_outside_the_mas_scope(): void
    {
        $maA = User::query()->create(['name' => 'MA A', 'email' => 'ma-a-detail@paygrid.local', 'role' => 'ma', 'password' => 'secret']);
        $maB = User::query()->create(['name' => 'MA B', 'email' => 'ma-b-detail@paygrid.local', 'role' => 'ma', 'password' => 'secret']);
        $agentB = Agent::query()->create(['ma_user_id' => $maB->id, 'code' => 'AG-B2', 'name' => 'Agent B2']);
        Merchant::query()->create(['slug' => 'store-b-only', 'name' => 'Store B Only', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'agent_id' => $agentB->id, 'approval_status' => 'approved']);

        $service = new MaAssistantService(new CsScopeResolver);

        $result = $this->invokeTool($service, 'toolMerchantDetail', [$maA, ['name' => 'Store B Only']]);

        $this->assertArrayHasKey('error', $result, "MA A must not be able to fetch MA B's store detail even by exact name.");
    }

    public function test_recent_transactions_are_scoped_to_the_asking_mas_merchants_only(): void
    {
        $maA = User::query()->create(['name' => 'MA A', 'email' => 'ma-a-trx@paygrid.local', 'role' => 'ma', 'password' => 'secret']);
        $maB = User::query()->create(['name' => 'MA B', 'email' => 'ma-b-trx@paygrid.local', 'role' => 'ma', 'password' => 'secret']);
        $agentA = Agent::query()->create(['ma_user_id' => $maA->id, 'code' => 'AG-A3', 'name' => 'Agent A3']);
        $agentB = Agent::query()->create(['ma_user_id' => $maB->id, 'code' => 'AG-B3', 'name' => 'Agent B3']);
        $storeA = Merchant::query()->create(['slug' => 'store-a-trx', 'name' => 'Store A Trx', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'agent_id' => $agentA->id, 'approval_status' => 'approved']);
        $storeB = Merchant::query()->create(['slug' => 'store-b-trx', 'name' => 'Store B Trx', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'agent_id' => $agentB->id, 'approval_status' => 'approved']);

        TopupRequest::query()->create(['merchant_id' => $storeA->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-a', 'status' => 'success', 'amount' => 1000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $storeB->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-b', 'status' => 'success', 'amount' => 2000, 'submitted_at' => now()]);

        $service = new MaAssistantService(new CsScopeResolver);
        $result = $this->invokeTool($service, 'toolRecentTransactions', [$maA, []]);
        $merchants = array_column($result['transactions'], 'merchant');

        $this->assertContains('Store A Trx', $merchants);
        $this->assertNotContains('Store B Trx', $merchants);
    }
}
