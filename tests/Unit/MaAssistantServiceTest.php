<?php

namespace Tests\Unit;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\CsScopeResolver;
use App\Services\MaAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaAssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeMa(): User
    {
        return User::query()->create(['name' => 'MA Gemini', 'email' => 'ma-gemini@paygrid.local', 'role' => 'ma', 'password' => 'secret']);
    }

    public function test_it_returns_a_friendly_message_when_no_api_key_is_configured(): void
    {
        config(['paygrid.assistant.api_key' => '']);
        $service = new MaAssistantService(new CsScopeResolver);

        $reply = $service->ask($this->makeMa(), 'Halo', []);

        $this->assertStringContainsString('belum aktif', $reply);
    }

    public function test_it_runs_a_tool_and_returns_geminis_final_answer(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key', 'paygrid.assistant.model' => 'gemini-3.8-flash']);
        $ma = $this->makeMa();
        $agent = Agent::query()->create(['ma_user_id' => $ma->id, 'code' => 'AG-GEM', 'name' => 'Agent Gemini']);
        $merchant = Merchant::query()->create(['slug' => 'store-gemini', 'name' => 'Store Gemini', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'agent_id' => $agent->id, 'approval_status' => 'approved']);
        TopupRequest::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-gem-1', 'status' => 'success', 'amount' => 75000, 'submitted_at' => now()]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'functionCall' => ['name' => 'get_overview_summary', 'args' => ['period' => 'today']],
                                'thoughtSignature' => 'fake-signature',
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [['text' => 'Volume sukses hari ini Rp75.000 dari 1 transaksi.']]],
                    ]],
                ]),
        ]);

        $reply = (new MaAssistantService(new CsScopeResolver))->ask($ma, 'Gimana volume hari ini?', []);

        $this->assertSame('Volume sukses hari ini Rp75.000 dari 1 transaksi.', $reply);

        // The thoughtSignature from turn 1 must be replayed verbatim on turn 2 -
        // Gemini 3.x rejects a functionCall history entry that's missing it.
        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'] ?? [];
            foreach ($contents as $entry) {
                foreach ($entry['parts'] ?? [] as $part) {
                    if (($part['thoughtSignature'] ?? null) === 'fake-signature') {
                        return true;
                    }
                }
            }

            return false;
        });

        // The real, MA-scoped tool result (not a guess) must have been sent back to Gemini.
        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'] ?? [];
            foreach ($contents as $entry) {
                foreach ($entry['parts'] ?? [] as $part) {
                    $response = $part['functionResponse']['response'] ?? null;
                    if ($response && ($response['volume_success_gross'] ?? null) === 75000) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    public function test_it_returns_a_friendly_message_on_rate_limit(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);

        $reply = (new MaAssistantService(new CsScopeResolver))->ask($this->makeMa(), 'Halo', []);

        $this->assertStringContainsString('banyak yang pakai', $reply);
    }

    public function test_it_maps_assistant_history_role_to_geminis_model_role(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke.']]]]],
            ]),
        ]);

        (new MaAssistantService(new CsScopeResolver))->ask($this->makeMa(), 'Lanjut', [
            ['role' => 'user', 'content' => 'Pertanyaan pertama'],
            ['role' => 'assistant', 'content' => 'Jawaban pertama'],
        ]);

        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'] ?? [];

            return ($contents[1]['role'] ?? null) === 'model' && ($contents[1]['parts'][0]['text'] ?? null) === 'Jawaban pertama';
        });
    }
}
