<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpsAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function setOpsToken(): void
    {
        config(['services.ops_assistant.token' => 'ops-secret']);
    }

    private function makeAgent(array $attrs = []): Agent
    {
        return Agent::query()->create(array_merge([
            'code' => 'AGN-'.Str::random(6),
            'name' => 'Test Agent',
        ], $attrs));
    }

    public function test_endpoints_require_a_valid_bearer_token(): void
    {
        $this->setOpsToken();
        $agent = $this->makeAgent();

        $this->postJson('/api/ops/merchants', [
            'name' => 'Test Toko',
            'agent_id' => $agent->id,
            'admin_email' => 'toko@example.com',
            'merchant_mdr_percent' => 1.2,
        ])->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer wrong')->getJson('/api/ops/merchants')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer wrong')->getJson('/api/ops/summary')->assertStatus(401);
    }

    public function test_creates_a_merchant_with_admin_login_and_fee_snapshot(): void
    {
        $this->setOpsToken();
        $ma = User::factory()->create(['role' => 'ma']);
        $agent = $this->makeAgent(['ma_user_id' => $ma->id, 'fee_menu_rates' => ['everyday_sc' => 0.9]]);

        $response = $this->withHeader('Authorization', 'Bearer ops-secret')
            ->postJson('/api/ops/merchants', [
                'name' => 'Toko EZE Test',
                'agent_id' => $agent->id,
                'admin_email' => 'opsassistant@example.com',
                'merchant_id' => 'hg-merchant-id-123',
                'merchant_key' => 'hg-merchant-key-456',
                'merchant_mdr_percent' => 1.2,
            ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $merchant = Merchant::query()->where('name', 'Toko EZE Test')->firstOrFail();
        $this->assertSame($agent->id, $merchant->agent_id);
        $this->assertSame('everyday_sc', $merchant->fee_menu);
        $this->assertSame('1.2000', $merchant->merchant_mdr_percent);
        $this->assertSame('0.9000', $merchant->agent_fee_percent);
        $this->assertSame('everyday', $merchant->settlement_method);
        $this->assertSame('hg-merchant-id-123', $merchant->merchant_id);

        $admin = User::query()->where('email', 'opsassistant@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame($merchant->id, $admin->merchant_id);
    }

    public function test_resolves_agent_by_fuzzy_name_and_rejects_ambiguous_matches(): void
    {
        $this->setOpsToken();
        $this->makeAgent(['code' => 'AGN-1', 'name' => 'NP x EZE Group']);
        $this->makeAgent(['code' => 'AGN-2', 'name' => 'NP x EZE Backup']);

        $this->withHeader('Authorization', 'Bearer ops-secret')
            ->postJson('/api/ops/merchants', [
                'name' => 'Toko Ambigu',
                'agent_name' => 'EZE',
                'admin_email' => 'ambigu@example.com',
                'merchant_mdr_percent' => 1,
            ])->assertStatus(422)->assertJsonStructure(['error', 'candidates']);

        $this->assertDatabaseMissing('merchants', ['name' => 'Toko Ambigu']);
    }

    public function test_rejects_duplicate_admin_email(): void
    {
        $this->setOpsToken();
        $agent = $this->makeAgent();
        User::factory()->create(['email' => 'sudah-ada@example.com']);

        $this->withHeader('Authorization', 'Bearer ops-secret')
            ->postJson('/api/ops/merchants', [
                'name' => 'Toko Dobel',
                'agent_id' => $agent->id,
                'admin_email' => 'sudah-ada@example.com',
                'merchant_mdr_percent' => 1,
            ])->assertStatus(422);
    }

    public function test_summary_and_list_merchants_return_scoped_json(): void
    {
        $this->setOpsToken();
        $agent = $this->makeAgent(['name' => 'Agen Ringkasan']);
        $merchant = Merchant::query()->create([
            'slug' => 'toko-ringkasan',
            'name' => 'Toko Ringkasan',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'agent_id' => $agent->id,
            'approval_status' => 'approved',
        ]);
        TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => 'hilogate',
            'gateway_ref_id' => 'ref-ringkasan',
            'status' => 'success',
            'amount' => 50000,
            'submitted_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ops-secret')
            ->getJson('/api/ops/summary')
            ->assertOk()
            ->assertJsonPath('trx_success', 1)
            ->assertJsonPath('volume_success_gross', 50000);

        $this->withHeader('Authorization', 'Bearer ops-secret')
            ->getJson('/api/ops/merchants?search=Ringkasan')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Toko Ringkasan']);
    }

    public function test_query_endpoint_runs_an_arbitrary_select(): void
    {
        $this->setOpsToken();
        $agent = $this->makeAgent(['name' => 'Agen Query']);
        Merchant::query()->create([
            'slug' => 'toko-query',
            'name' => 'Toko Query',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'agent_id' => $agent->id,
            'approval_status' => 'approved',
        ]);

        $this->withHeader('Authorization', 'Bearer ops-secret')
            ->postJson('/api/ops/query', ['sql' => "SELECT name, approval_status FROM merchants WHERE name = 'Toko Query'"])
            ->assertOk()
            ->assertJsonPath('row_count', 1)
            ->assertJsonFragment(['name' => 'Toko Query', 'approval_status' => 'approved']);
    }

    public function test_query_endpoint_requires_a_valid_token(): void
    {
        $this->setOpsToken();

        $this->postJson('/api/ops/query', ['sql' => 'SELECT id FROM merchants'])->assertStatus(401);
    }

    public function test_query_endpoint_rejects_wildcard_columns(): void
    {
        $this->setOpsToken();

        $this->withHeader('Authorization', 'Bearer ops-secret')
            ->postJson('/api/ops/query', ['sql' => 'SELECT * FROM merchants'])
            ->assertStatus(422);
    }

    public function test_query_endpoint_rejects_non_select_statements(): void
    {
        $this->setOpsToken();
        $agent = $this->makeAgent();

        foreach ([
            "UPDATE merchants SET name = 'x' WHERE id = 1",
            "DELETE FROM merchants WHERE id = 1",
            "DROP TABLE merchants",
            "SELECT id FROM merchants; DROP TABLE merchants",
            "SELECT id, name FROM merchants INTO OUTFILE '/tmp/x.csv'",
        ] as $sql) {
            $this->withHeader('Authorization', 'Bearer ops-secret')
                ->postJson('/api/ops/query', ['sql' => $sql])
                ->assertStatus(422);
        }

        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
    }

    public function test_query_endpoint_rejects_credential_columns(): void
    {
        $this->setOpsToken();

        $this->withHeader('Authorization', 'Bearer ops-secret')
            ->postJson('/api/ops/query', ['sql' => 'SELECT id, password FROM users'])
            ->assertStatus(422);
    }
}
