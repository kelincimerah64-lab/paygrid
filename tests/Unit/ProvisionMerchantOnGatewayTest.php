<?php

namespace Tests\Unit;

use App\Jobs\ProvisionMerchantOnGateway;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Gateway\GatewayClientInterface;
use App\Services\Gateway\HilogateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionMerchantOnGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function fakeHilogateClient(): object
    {
        return new class implements GatewayClientInterface
        {
            public array $lastPayload = [];

            public function createQrisTransaction(Merchant $merchant, string $reference, int $amount, int $expiresInMinutes = 30): array
            {
                return [];
            }

            public function getTransaction(Merchant $merchant, string $reference): array
            {
                return [];
            }

            public function pullTransactions(Merchant $merchant, array $filters = []): array
            {
                return [];
            }

            public function pullSettlements(Merchant $merchant, array $filters = []): array
            {
                return [];
            }

            public function createMerchant(array $payload): array
            {
                $this->lastPayload = $payload;

                return ['merchantId' => 'fake-merchant-id', 'merchantKey' => 'fake-merchant-key'];
            }
        };
    }

    private function merchantUnderAgent(User $ma): Merchant
    {
        $agent = Agent::query()->create([
            'ma_user_id' => $ma->id,
            'code' => 'AG-'.$ma->id,
            'name' => 'Agent for '.$ma->name,
        ]);

        return Merchant::query()->create([
            'slug' => 'provision-test-'.$ma->id,
            'name' => 'Provision Test Store',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'agent_id' => $agent->id,
            'approval_status' => 'approved',
        ]);
    }

    public function test_provisioning_uses_the_approving_mas_own_hilogate_credentials_when_set(): void
    {
        $ma = User::query()->create([
            'name' => 'MA With Own Account',
            'email' => 'ma-own-account@paygrid.local',
            'role' => 'ma',
            'password' => 'secret',
            'hilogate_onboarding_email' => 'ma-own@hilogate.com',
            'hilogate_onboarding_password' => 'ma-own-secret',
        ]);
        $merchant = $this->merchantUnderAgent($ma);

        $fake = $this->fakeHilogateClient();
        $this->app->instance(HilogateClient::class, $fake);

        ProvisionMerchantOnGateway::dispatchSync($merchant->id);

        $this->assertSame('ma-own@hilogate.com', $fake->lastPayload['onboarding_email']);
        $this->assertSame('ma-own-secret', $fake->lastPayload['onboarding_password']);
        $this->assertSame('fake-merchant-id', $merchant->fresh()->merchant_id);
    }

    public function test_provisioning_falls_back_to_global_credentials_when_the_ma_has_none_set(): void
    {
        $ma = User::query()->create([
            'name' => 'MA Without Own Account',
            'email' => 'ma-no-account@paygrid.local',
            'role' => 'ma',
            'password' => 'secret',
        ]);
        $merchant = $this->merchantUnderAgent($ma);

        $fake = $this->fakeHilogateClient();
        $this->app->instance(HilogateClient::class, $fake);

        ProvisionMerchantOnGateway::dispatchSync($merchant->id);

        $this->assertNull($fake->lastPayload['onboarding_email']);
        $this->assertNull($fake->lastPayload['onboarding_password']);
    }
}
