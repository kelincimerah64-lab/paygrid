<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only Q&A assistant for the MA dashboard. Every tool below takes the
 * authenticated MA as the first argument and scopes its query through
 * CsScopeResolver::merchantIds() - the AI never supplies a merchant/agent id
 * directly, only a name/slug to search *within* that scope. This is what
 * guarantees one MA's chat can never surface another MA's data, regardless
 * of what is asked. No tool here writes anything - this assistant can only
 * answer questions, never change data or code.
 *
 * Backed by Gemini's generateContent API (function calling), not an SDK -
 * same pattern as HilogateClient/JiraClient elsewhere in this codebase.
 */
class MaAssistantService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah asisten AI di dashboard PayGrid, khusus membantu MA (Master Agent) menjawab pertanyaan soal dashboard dan data toko mereka sendiri. Jawab dalam Bahasa Indonesia, singkat dan jelas, seperti menjelaskan ke rekan kerja non-teknis.

Kamu HANYA bisa menjawab pertanyaan. Kamu TIDAK bisa dan TIDAK BOLEH mengklaim bisa: mengubah data, memperbaiki bug, mengubah kode, menjalankan aksi apapun, atau menghubungi pihak lain. Kalau diminta melakukan itu, jelaskan dengan sopan bahwa kamu cuma bisa menjawab pertanyaan, dan sarankan menghubungi tim support PayGrid untuk tindakan.

Konteks penting soal istilah di dashboard PayGrid:
- "Successful Volume" (kotak kecil dengan sparkline & "vs Kemarin") = total nominal KOTOR transaksi sukses HARI INI SAJA (bukan akumulasi), dibandingkan hari kemarin.
- "Total Liquidity (Volume Sukses)" (kartu besar di atas) = akumulasi total nominal kotor transaksi sukses dari AWAL BULAN sampai sekarang, bukan harian.
- "Revenue Today" = total fee/MDR yang kepotong dari transaksi sukses hari ini (amount dikurangi net_amount) - ini TOTAL fee gabungan semua pihak (Hilogate + Agent + MA), BUKAN pendapatan bersih MA sendiri.
- "Fee MA" (chip di kartu atas) = bagian fee yang beneran jadi hak MA saja, setelah dipisah dari bagian Agent dan gateway.
- "Settlement" / net_amount = nominal yang diterima toko setelah dipotong fee gateway.
- Semua transaksi disimpan di database dalam UTC, lalu dikonversi ke WIB (+7 jam) saat ditampilkan.
- Sync data dari Hilogate ke PayGrid jalan otomatis tiap beberapa puluh detik. Kalau data yang sangat baru belum muncul, biasanya cuma soal beberapa detik jeda sync, bukan berarti hilang.
- Toko dengan tipe "cm" pakai alur Topup/Checklist manual oleh CS; toko tipe "script" cuma punya halaman History (tanpa checklist).

Kamu punya beberapa tool buat narik data ASLI milik MA yang sedang tanya - selalu pakai tool itu kalau pertanyaannya soal angka/data spesifik ("kenapa volume saya turun", "toko apa yang paling rame", dst), jangan menebak angka. Kalau pertanyaannya soal konsep/istilah umum, jawab langsung dari pengetahuan di atas tanpa perlu tool.

Kalau user melampirkan gambar (screenshot dashboard), baca angka/informasi di gambar itu buat bantu jawab pertanyaannya.
PROMPT;

    public function __construct(private CsScopeResolver $scope) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history  Prior turns from the client: {role: user|assistant, content: string}.
     * @param  array{data: string, mediaType: string}|null  $image  Base64 image data + MIME type, if the user attached one.
     */
    public function ask(User $ma, string $message, array $history, ?array $image = null): string
    {
        $apiKey = (string) config('paygrid.assistant.api_key');
        if ($apiKey === '') {
            Log::warning('paygrid.ma_assistant.missing_api_key');

            return 'Fitur ini belum aktif - kredensial AI belum dikonfigurasi. Hubungi tim PayGrid.';
        }

        $model = (string) config('paygrid.assistant.model', 'gemini-3.8-flash');

        $contents = array_map(fn ($turn) => [
            'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $turn['content']]],
        ], $history);

        $userParts = [];
        if ($image) {
            $userParts[] = ['inlineData' => ['mimeType' => $image['mediaType'], 'data' => $image['data']]];
        }
        $userParts[] = ['text' => $message];
        $contents[] = ['role' => 'user', 'parts' => $userParts];

        $tools = [['functionDeclarations' => $this->toolDefinitions()]];

        for ($turn = 0; $turn < 6; $turn++) {
            // Gemini's free-tier capacity returns 503 "high demand" fairly often and
            // it's usually gone within a couple seconds, so one quick retry here avoids
            // surfacing a hard error for what's normally a transient blip.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $response = Http::timeout(30)->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?".http_build_query(['key' => $apiKey]),
                        [
                            'systemInstruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                            'contents' => $contents,
                            'tools' => $tools,
                        ],
                    );
                } catch (\Throwable $e) {
                    Log::warning('paygrid.ma_assistant.request_failed', ['message' => $e->getMessage()]);

                    return 'Maaf, ada gangguan sesaat. Coba lagi ya.';
                }

                if ($response->status() !== 503) {
                    break;
                }
            }

            if ($response->status() === 429 || $response->status() === 503) {
                return 'Lagi banyak yang pakai AI-nya, coba lagi sebentar ya.';
            }
            if (! $response->successful()) {
                Log::warning('paygrid.ma_assistant.api_error', ['status' => $response->status(), 'body' => $response->body()]);

                return 'Maaf, ada gangguan sesaat. Coba lagi ya.';
            }

            $parts = $response->json('candidates.0.content.parts') ?? [];
            $functionCalls = array_filter($parts, fn ($p) => isset($p['functionCall']));

            if ($functionCalls === []) {
                return $this->textFrom($parts);
            }

            // json_decode(..., true) turns Gemini's "args": {} into a PHP [],
            // and json_encode(['args' => []]) on the next turn re-serializes
            // that as a JSON array - which Gemini's strict proto parser
            // rejects for a map-typed field ("cannot start list"). Cast back
            // to an object so a no-arg tool call can be replayed in history.
            $contents[] = ['role' => 'model', 'parts' => array_map(function ($part) {
                if (isset($part['functionCall']['args']) && $part['functionCall']['args'] === []) {
                    $part['functionCall']['args'] = (object) [];
                }

                return $part;
            }, $parts)];

            $responseParts = [];
            foreach ($functionCalls as $part) {
                $call = $part['functionCall'];
                $responseParts[] = [
                    'functionResponse' => [
                        'name' => $call['name'],
                        'response' => $this->runTool($ma, $call['name'], $call['args'] ?? []),
                    ],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        return 'Maaf, pertanyaan ini butuh terlalu banyak langkah buat dijawab. Coba pecah jadi pertanyaan yang lebih spesifik ya.';
    }

    private function textFrom(array $parts): string
    {
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                return $part['text'];
            }
        }

        return 'Maaf, saya tidak bisa memproses jawabannya.';
    }

    private function runTool(User $ma, string $name, array $input): array
    {
        try {
            return match ($name) {
                'get_overview_summary' => $this->toolOverviewSummary($ma, $input),
                'list_merchants' => $this->toolListMerchants($ma, $input),
                'get_merchant_detail' => $this->toolMerchantDetail($ma, $input),
                'list_recent_transactions' => $this->toolRecentTransactions($ma, $input),
                'list_agents' => $this->toolListAgents($ma),
                'get_sync_health' => $this->toolSyncHealth($ma),
                default => ['error' => "Tool tidak dikenal: {$name}"],
            };
        } catch (\Throwable $e) {
            Log::warning('paygrid.ma_assistant.tool_failed', ['tool' => $name, 'input' => $input, 'message' => $e->getMessage()]);

            return ['error' => 'Terjadi kesalahan saat mengambil data.'];
        }
    }

    private function merchantScope(User $ma)
    {
        return Merchant::query()->whereIn('id', $this->scope->merchantIds($ma));
    }

    private function toolOverviewSummary(User $ma, array $input): array
    {
        $period = $input['period'] ?? 'today';
        [$from, $to] = match ($period) {
            'this_month' => [now('Asia/Jakarta')->startOfMonth(), now('Asia/Jakarta')],
            'last_30_days' => [now('Asia/Jakarta')->subDays(29)->startOfDay(), now('Asia/Jakarta')],
            default => [now('Asia/Jakarta')->startOfDay(), now('Asia/Jakarta')],
        };

        $merchantIds = $this->scope->merchantIds($ma);
        $row = TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->whereBetween('submitted_at', [$from->utc(), $to->utc()])
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as trx_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN fee_amount ELSE 0 END), 0) as total_fee")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as trx_pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->first();

        return [
            'period' => $period,
            'trx_success' => (int) $row->trx_success,
            'volume_success_gross' => (int) $row->volume_success,
            'total_fee_all_parties' => (int) $row->total_fee,
            'trx_pending' => (int) $row->trx_pending,
            'pending_amount' => (int) $row->pending_amount,
            'merchant_count' => count($merchantIds),
        ];
    }

    private function toolListMerchants(User $ma, array $input): array
    {
        $rows = $this->merchantScope($ma)
            ->when(! empty($input['search']), fn ($q) => $q->where('name', 'like', '%'.$input['search'].'%'))
            ->when(! empty($input['status']), fn ($q) => $q->where('approval_status', $input['status']))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'merchant_type', 'approval_status', 'gateway']);

        return ['merchants' => $rows->map(fn ($m) => [
            'name' => $m->name,
            'type' => $m->merchant_type,
            'status' => $m->approval_status,
            'gateway' => $m->gateway,
        ])->all()];
    }

    private function toolMerchantDetail(User $ma, array $input): array
    {
        $merchant = $this->merchantScope($ma)->where('name', 'like', '%'.($input['name'] ?? '').'%')->first();
        if (! $merchant) {
            return ['error' => 'Toko tidak ditemukan di daftar toko MA ini.'];
        }

        $stats = TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->whereDate('submitted_at', '>=', now('Asia/Jakarta')->subDays(6)->startOfDay()->utc())
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as trx_success_7d")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume_7d")
            ->first();

        $lastSync = GatewaySyncLog::query()->where('merchant_id', $merchant->id)->where('direction', 'pull')->latest('finished_at')->first();

        return [
            'name' => $merchant->name,
            'type' => $merchant->merchant_type,
            'status' => $merchant->approval_status,
            'trx_success_last_7_days' => (int) $stats->trx_success_7d,
            'volume_last_7_days_gross' => (int) $stats->volume_7d,
            'last_sync_status' => $lastSync?->status ?? 'belum pernah sync',
            'last_sync_at_wib' => $lastSync?->finished_at?->timezone('Asia/Jakarta')->toDateTimeString(),
        ];
    }

    private function toolRecentTransactions(User $ma, array $input): array
    {
        $limit = min(50, max(1, (int) ($input['limit'] ?? 10)));
        $query = TopupRequest::query()->whereIn('merchant_id', $this->scope->merchantIds($ma))->with('merchant:id,name');

        if (! empty($input['merchant_name'])) {
            $query->whereHas('merchant', fn ($q) => $q->where('name', 'like', '%'.$input['merchant_name'].'%'));
        }
        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        $rows = $query->latest('submitted_at')->limit($limit)->get();

        return ['transactions' => $rows->map(fn ($t) => [
            'merchant' => $t->merchant?->name,
            'status' => $t->status,
            'amount' => (int) $t->amount,
            'submitted_at_wib' => $t->submitted_at?->timezone('Asia/Jakarta')->toDateTimeString(),
            'rrn' => $t->rrn,
        ])->all()];
    }

    private function toolListAgents(User $ma): array
    {
        $rows = Agent::query()->where('ma_user_id', $ma->id)->withCount('merchants')->get();

        return ['agents' => $rows->map(fn ($a) => [
            'name' => $a->name,
            'store_count' => $a->merchants_count,
            'active' => (bool) $a->is_active,
        ])->all()];
    }

    private function toolSyncHealth(User $ma): array
    {
        $merchantIds = $this->scope->merchantIds($ma);
        $failures = GatewaySyncLog::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'failed')
            ->where('started_at', '>=', now()->subHours(2))
            ->with('merchant:id,name')
            ->latest('started_at')
            ->limit(10)
            ->get();

        return ['recent_sync_failures' => $failures->map(fn ($f) => [
            'merchant' => $f->merchant?->name,
            'http_status' => $f->http_status,
            'message' => str($f->message)->limit(150)->toString(),
            'at_wib' => $f->started_at?->timezone('Asia/Jakarta')->toDateTimeString(),
        ])->all()];
    }

    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'get_overview_summary',
                'description' => 'Ambil ringkasan volume, jumlah transaksi sukses, dan fee untuk MA ini pada periode tertentu.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'period' => ['type' => 'STRING', 'enum' => ['today', 'this_month', 'last_30_days'], 'description' => 'Periode data, default today'],
                    ],
                ],
            ],
            [
                'name' => 'list_merchants',
                'description' => 'Daftar toko milik MA ini, bisa difilter nama atau status approval.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'search' => ['type' => 'STRING', 'description' => 'Cari sebagian nama toko'],
                        'status' => ['type' => 'STRING', 'description' => 'Filter status approval, misal approved'],
                    ],
                ],
            ],
            [
                'name' => 'get_merchant_detail',
                'description' => 'Detail 1 toko spesifik milik MA ini: volume 7 hari terakhir, status sync terakhir.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['name' => ['type' => 'STRING', 'description' => 'Nama toko (boleh sebagian)']],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'list_recent_transactions',
                'description' => 'Transaksi terbaru milik MA ini, bisa difilter per toko atau status.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'merchant_name' => ['type' => 'STRING', 'description' => 'Filter nama toko (boleh sebagian)'],
                        'status' => ['type' => 'STRING', 'description' => 'success, pending, expired, dll'],
                        'limit' => ['type' => 'INTEGER', 'description' => 'Jumlah baris, default 10, maksimal 50'],
                    ],
                ],
            ],
            [
                'name' => 'list_agents',
                'description' => 'Daftar agen di bawah MA ini beserta jumlah toko masing-masing.',
                'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
            ],
            [
                'name' => 'get_sync_health',
                'description' => 'Kegagalan sinkronisasi data gateway terbaru (2 jam terakhir) untuk toko-toko milik MA ini - berguna buat jawab pertanyaan soal data yang telat/tidak muncul.',
                'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
            ],
        ];
    }
}
