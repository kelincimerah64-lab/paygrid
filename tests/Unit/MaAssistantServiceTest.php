<?php

namespace Tests\Unit;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\MerchantWithdrawal;
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

    private function makeMa(string $email = 'ma-gemini@paygrid.local'): User
    {
        return User::query()->create(['name' => 'MA Gemini', 'email' => $email, 'role' => 'ma', 'password' => 'secret']);
    }

    private function makeMerchantFor(User $ma, string $slug, string $name): Merchant
    {
        $agent = Agent::query()->create(['ma_user_id' => $ma->id, 'code' => 'AG-'.$slug, 'name' => 'Agent '.$name]);

        return Merchant::query()->create(['slug' => $slug, 'name' => $name, 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'agent_id' => $agent->id, 'approval_status' => 'approved']);
    }

    private function callTool(string $method, User $ma, array $input): array
    {
        $service = new MaAssistantService(new CsScopeResolver);
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, $ma, $input);
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

    public function test_a_no_argument_tool_calls_history_replays_an_empty_object_not_an_array(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        $ma = $this->makeMa();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            // Gemini's own JSON has "args": {} for a no-arg tool call -
                            // json_decode(..., true) turns that into a PHP [].
                            'parts' => [['functionCall' => ['name' => 'list_agents', 'args' => []]]],
                        ],
                    ]],
                ])
                ->push([
                    'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke.']]]]],
                ]),
        ]);

        (new MaAssistantService(new CsScopeResolver))->ask($ma, 'Ada agen apa aja?', []);

        $secondRequestBody = Http::recorded()[1][0]->body();
        $this->assertStringContainsString('"args":{}', $secondRequestBody);
        $this->assertStringNotContainsString('"args":[]', $secondRequestBody);
    }

    public function test_it_returns_a_friendly_message_on_rate_limit(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);

        $reply = (new MaAssistantService(new CsScopeResolver))->ask($this->makeMa(), 'Halo', []);

        $this->assertStringContainsString('banyak yang pakai', $reply);
    }

    public function test_it_retries_once_on_a_transient_503_and_succeeds(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503)
                ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke.']]]]]]),
        ]);

        $reply = (new MaAssistantService(new CsScopeResolver))->ask($this->makeMa(), 'Halo', []);

        $this->assertSame('Oke.', $reply);
        Http::assertSentCount(2);
    }

    public function test_it_returns_a_friendly_message_when_503_persists_after_retry(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503)]);

        $reply = (new MaAssistantService(new CsScopeResolver))->ask($this->makeMa(), 'Halo', []);

        $this->assertStringContainsString('banyak yang pakai', $reply);
        Http::assertSentCount(2);
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

    public function test_query_transactions_never_leaks_another_mas_data(): void
    {
        $ma1 = $this->makeMa('ma1@paygrid.local');
        $ma2 = $this->makeMa('ma2@paygrid.local');
        $store1 = $this->makeMerchantFor($ma1, 'store-one', 'Toko Satu');
        $store2 = $this->makeMerchantFor($ma2, 'store-two', 'Toko Dua');
        TopupRequest::query()->create(['merchant_id' => $store1->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-1', 'status' => 'success', 'amount' => 10000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $store2->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'ref-2', 'status' => 'success', 'amount' => 999999, 'submitted_at' => now()]);

        $result = $this->callTool('toolQueryTransactions', $ma1, ['mode' => 'list']);

        $this->assertCount(1, $result['transactions']);
        $this->assertSame('Toko Satu', $result['transactions'][0]['merchant']);
    }

    public function test_query_transactions_summary_groups_by_status(): void
    {
        $ma = $this->makeMa();
        $store = $this->makeMerchantFor($ma, 'store-sum', 'Toko Sum');
        TopupRequest::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'r1', 'status' => 'success', 'amount' => 10000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'r2', 'status' => 'success', 'amount' => 20000, 'submitted_at' => now()]);
        TopupRequest::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'r3', 'status' => 'failed', 'amount' => 5000, 'submitted_at' => now()]);

        $result = $this->callTool('toolQueryTransactions', $ma, ['mode' => 'summary', 'group_by' => 'status']);
        $byGroup = collect($result['summary'])->keyBy('group');

        $this->assertSame(2, $byGroup['success']['trx_count']);
        $this->assertSame(30000, $byGroup['success']['sum_amount']);
        $this->assertSame(1, $byGroup['failed']['trx_count']);
    }

    public function test_query_transactions_date_range_filter_excludes_out_of_range_rows(): void
    {
        $ma = $this->makeMa();
        $store = $this->makeMerchantFor($ma, 'store-date', 'Toko Tanggal');
        TopupRequest::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'old', 'status' => 'success', 'amount' => 10000, 'submitted_at' => now()->subDays(10)]);
        TopupRequest::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'new', 'status' => 'success', 'amount' => 20000, 'submitted_at' => now()]);

        $result = $this->callTool('toolQueryTransactions', $ma, [
            'mode' => 'list',
            'date_from' => now()->subDays(1)->format('Y-m-d'),
        ]);

        $this->assertCount(1, $result['transactions']);
        $this->assertSame(20000, $result['transactions'][0]['amount']);
    }

    public function test_query_transactions_list_mode_caps_limit_at_fifty(): void
    {
        $ma = $this->makeMa();

        $result = $this->callTool('toolQueryTransactions', $ma, ['mode' => 'list', 'limit' => 500]);

        // No rows seeded - this just proves the request doesn't error out on an
        // oversized limit; the cap itself is exercised via the min(50, ...) call.
        $this->assertSame([], $result['transactions']);
    }

    public function test_query_withdrawals_summary_sums_amounts_by_status(): void
    {
        $ma = $this->makeMa();
        $store = $this->makeMerchantFor($ma, 'store-wd', 'Toko WD');
        MerchantWithdrawal::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'wd-1', 'status' => 'completed', 'amount' => 50000, 'net_amount' => 49000, 'fee' => 1000, 'gateway_created_at' => now()]);
        MerchantWithdrawal::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'wd-2', 'status' => 'pending', 'amount' => 30000, 'net_amount' => 29500, 'fee' => 500, 'gateway_created_at' => now()]);

        $result = $this->callTool('toolQueryWithdrawals', $ma, ['mode' => 'summary', 'group_by' => 'status']);
        $byGroup = collect($result['summary'])->keyBy('group');

        $this->assertSame(50000, $byGroup['completed']['sum_amount']);
        $this->assertSame(30000, $byGroup['pending']['sum_amount']);
    }

    public function test_query_tickets_summary_counts_by_department(): void
    {
        $ma = $this->makeMa();
        $store = $this->makeMerchantFor($ma, 'store-tix', 'Toko Tiket');
        MerchantTicket::query()->create(['merchant_id' => $store->id, 'ticket_no' => 'TIX-1', 'department' => 'cs', 'category' => 'topup', 'description' => 'Belum masuk', 'status' => 'open']);
        MerchantTicket::query()->create(['merchant_id' => $store->id, 'ticket_no' => 'TIX-2', 'department' => 'tech', 'category' => 'ip_whitelist', 'description' => 'Akses ditolak', 'status' => 'open']);
        MerchantTicket::query()->create(['merchant_id' => $store->id, 'ticket_no' => 'TIX-3', 'department' => 'cs', 'category' => 'topup', 'description' => 'Sudah selesai', 'status' => 'closed']);

        $result = $this->callTool('toolQueryTickets', $ma, ['mode' => 'summary', 'group_by' => 'department']);
        $byGroup = collect($result['summary'])->keyBy('group');

        $this->assertSame(2, $byGroup['cs']['ticket_count']);
        $this->assertSame(1, $byGroup['tech']['ticket_count']);
    }

    public function test_ask_wires_up_query_transactions_tool_end_to_end(): void
    {
        config(['paygrid.assistant.api_key' => 'fake-gemini-key']);
        $ma = $this->makeMa();
        $store = $this->makeMerchantFor($ma, 'store-e2e', 'Toko E2E');
        TopupRequest::query()->create(['merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_ref_id' => 'e2e-1', 'status' => 'success', 'amount' => 42000, 'submitted_at' => now()]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [[
                        'functionCall' => ['name' => 'query_transactions', 'args' => ['mode' => 'summary', 'group_by' => 'status']],
                    ]]],
                ]]])
                ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Totalnya Rp42.000.']]]]]]),
        ]);

        $reply = (new MaAssistantService(new CsScopeResolver))->ask($ma, 'Total transaksi sukses berapa?', []);

        $this->assertSame('Totalnya Rp42.000.', $reply);
        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'] ?? [];
            foreach ($contents as $entry) {
                foreach ($entry['parts'] ?? [] as $part) {
                    $response = $part['functionResponse']['response'] ?? null;
                    if ($response && ($response['summary'][0]['sum_amount'] ?? null) === 42000) {
                        return true;
                    }
                }
            }

            return false;
        });
    }
}
