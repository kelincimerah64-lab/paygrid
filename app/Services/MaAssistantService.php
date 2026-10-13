<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\MerchantWithdrawal;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

Format jawaban: teks polos aja, JANGAN pakai simbol markdown (**tebal**, *miring*, tanda bintang, dll) - chat ini nggak render markdown jadi simbolnya malah kelihatan apa adanya. Langsung ke inti, jangan bertele-tele atau ngulang-ngulang kalimat penutup kaya "ada yang mau ditanyakan lagi?" di tiap jawaban.

Kalau kamu udah tau faktanya dari konteks di bawah ini, jawab dengan yakin - jangan ragu-ragu atau gampang ganti jawaban cuma karena user mancing/nanya ulang dengan nada beda. Baru revisi jawaban kalau kamu emang salah atau ada data baru dari tool.

Kamu HANYA bisa menjawab pertanyaan. Kamu TIDAK bisa dan TIDAK BOLEH mengklaim bisa: mengubah data, memperbaiki bug, mengubah kode, menjalankan aksi apapun, atau menghubungi pihak lain. Kalau diminta melakukan itu, jelaskan dengan sopan bahwa kamu cuma bisa menjawab pertanyaan, dan sarankan menghubungi tim support PayGrid untuk tindakan.

Konteks penting soal istilah di dashboard PayGrid:
- "Successful Volume" (kotak kecil dengan sparkline & "vs Kemarin") = total nominal KOTOR transaksi sukses HARI INI SAJA (bukan akumulasi), dibandingkan hari kemarin.
- "Total Liquidity (Volume Sukses)" (kartu besar di atas) = akumulasi total nominal kotor transaksi sukses dari AWAL BULAN sampai sekarang, bukan harian.
- "Revenue Today" = total fee/MDR yang kepotong dari transaksi sukses hari ini (amount dikurangi net_amount) - ini TOTAL fee gabungan semua pihak (Hilogate + Agent + MA), BUKAN pendapatan bersih MA sendiri.
- "Fee MA" (chip di kartu atas) = bagian fee yang beneran jadi hak MA saja, setelah dipisah dari bagian Agent dan gateway.
- "Settlement" / net_amount = nominal yang diterima toko setelah dipotong fee gateway.
- "Available Balance" / Saldo Tersedia = diambil LANGSUNG dari endpoint balance gateway (Hilogate), BUKAN dihitung dari akumulasi transaksi di database lokal. PayGrid sync berkala ke endpoint itu dan simpan hasilnya (active_balance, pending_balance) di tabel merchant_gateway_balances - angka yang tampil adalah hasil sync terakhir, seakurat terakhir kali sync jalan.
- Semua transaksi disimpan di database dalam UTC, lalu dikonversi ke WIB (+7 jam) saat ditampilkan.
- Sync data dari Hilogate ke PayGrid jalan otomatis tiap beberapa puluh detik. Kalau data yang sangat baru belum muncul, biasanya cuma soal beberapa detik jeda sync, bukan berarti hilang.
- Toko dengan tipe "cm" pakai alur Topup/Checklist manual oleh CS; toko tipe "script" cuma punya halaman History (tanpa checklist).

Kamu punya beberapa tool buat narik data ASLI milik MA yang sedang tanya - selalu pakai tool itu kalau pertanyaannya soal angka/data spesifik ("kenapa volume saya turun", "toko apa yang paling rame", dst), jangan menebak angka. Kalau pertanyaannya soal konsep/istilah umum, jawab langsung dari pengetahuan di atas tanpa perlu tool.

Kalau pertanyaannya soal transaksi/withdrawal/tiket yang nggak pas sama tool spesifik yang ada (misal butuh filter atau pengelompokan tertentu), pakai tool query_transactions/query_withdrawals/query_tickets - itu tool serbaguna buat narik data dengan filter status/toko/tanggal dan pengelompokan sesuka kamu.

Kalau pertanyaannya masih belum kecover sama SEMUA tool di atas (butuh query custom/kompleks), pakai tool run_sql - kamu bisa nulis SQL SELECT bebas ke data toko MA ini.

Kalau user melampirkan gambar (screenshot dashboard), baca angka/informasi di gambar itu buat bantu jawab pertanyaannya.
PROMPT;

    private bool $sqlScopeReady = false;

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
                'query_transactions' => $this->toolQueryTransactions($ma, $input),
                'query_withdrawals' => $this->toolQueryWithdrawals($ma, $input),
                'query_tickets' => $this->toolQueryTickets($ma, $input),
                'run_sql' => $this->toolRunSql($ma, $input),
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

    private function applyDateRange($query, array $input, string $column): void
    {
        if (! empty($input['date_from'])) {
            $query->where($column, '>=', Carbon::parse($input['date_from'], 'Asia/Jakarta')->startOfDay()->utc());
        }
        if (! empty($input['date_to'])) {
            $query->where($column, '<=', Carbon::parse($input['date_to'], 'Asia/Jakarta')->endOfDay()->utc());
        }
    }

    private function toolQueryTransactions(User $ma, array $input): array
    {
        $query = TopupRequest::query()->whereIn('merchant_id', $this->scope->merchantIds($ma));

        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (! empty($input['merchant_name'])) {
            $query->whereHas('merchant', fn ($q) => $q->where('name', 'like', '%'.$input['merchant_name'].'%'));
        }
        $this->applyDateRange($query, $input, 'submitted_at');

        if (($input['mode'] ?? 'summary') === 'list') {
            $limit = min(50, max(1, (int) ($input['limit'] ?? 10)));
            $rows = $query->with('merchant:id,name')->latest('submitted_at')->limit($limit)->get();

            return ['transactions' => $rows->map(fn ($t) => [
                'merchant' => $t->merchant?->name,
                'status' => $t->status,
                'amount' => (int) $t->amount,
                'submitted_at_wib' => $t->submitted_at?->timezone('Asia/Jakarta')->toDateTimeString(),
            ])->all()];
        }

        $groupBy = $input['group_by'] ?? 'none';
        $groupExpr = match ($groupBy) {
            'status' => 'status',
            'merchant' => 'merchant_id',
            'day' => 'DATE(CONVERT_TZ(submitted_at, "+00:00", "+07:00"))',
            default => "'all'",
        };

        $rows = $query
            ->selectRaw("{$groupExpr} as group_key")
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as sum_amount')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as sum_net_amount')
            ->selectRaw('COALESCE(SUM(fee_amount), 0) as sum_fee')
            ->groupByRaw($groupExpr)
            ->orderByDesc('sum_amount')
            ->limit(50)
            ->get();

        $merchantNames = $groupBy === 'merchant'
            ? Merchant::query()->whereIn('id', $rows->pluck('group_key'))->pluck('name', 'id')
            : collect();

        return ['summary' => $rows->map(fn ($r) => [
            'group' => $groupBy === 'merchant' ? ($merchantNames[$r->group_key] ?? $r->group_key) : $r->group_key,
            'trx_count' => (int) $r->trx_count,
            'sum_amount' => (int) $r->sum_amount,
            'sum_net_amount' => (int) $r->sum_net_amount,
            'sum_fee' => (int) $r->sum_fee,
        ])->all()];
    }

    private function toolQueryWithdrawals(User $ma, array $input): array
    {
        $query = MerchantWithdrawal::query()->whereIn('merchant_id', $this->scope->merchantIds($ma));

        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (! empty($input['merchant_name'])) {
            $query->whereHas('merchant', fn ($q) => $q->where('name', 'like', '%'.$input['merchant_name'].'%'));
        }
        $this->applyDateRange($query, $input, 'gateway_created_at');

        if (($input['mode'] ?? 'summary') === 'list') {
            $limit = min(50, max(1, (int) ($input['limit'] ?? 10)));
            $rows = $query->with('merchant:id,name')->latest('gateway_created_at')->limit($limit)->get();

            return ['withdrawals' => $rows->map(fn ($w) => [
                'merchant' => $w->merchant?->name,
                'status' => $w->status,
                'amount' => (int) $w->amount,
                'net_amount' => (int) $w->net_amount,
                'created_at_wib' => $w->gateway_created_at?->timezone('Asia/Jakarta')->toDateTimeString(),
            ])->all()];
        }

        $groupBy = $input['group_by'] ?? 'none';
        $groupExpr = match ($groupBy) {
            'status' => 'status',
            'merchant' => 'merchant_id',
            'day' => 'DATE(CONVERT_TZ(gateway_created_at, "+00:00", "+07:00"))',
            default => "'all'",
        };

        $rows = $query
            ->selectRaw("{$groupExpr} as group_key")
            ->selectRaw('COUNT(*) as wd_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as sum_amount')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as sum_net_amount')
            ->selectRaw('COALESCE(SUM(fee), 0) as sum_fee')
            ->groupByRaw($groupExpr)
            ->orderByDesc('sum_amount')
            ->limit(50)
            ->get();

        $merchantNames = $groupBy === 'merchant'
            ? Merchant::query()->whereIn('id', $rows->pluck('group_key'))->pluck('name', 'id')
            : collect();

        return ['summary' => $rows->map(fn ($r) => [
            'group' => $groupBy === 'merchant' ? ($merchantNames[$r->group_key] ?? $r->group_key) : $r->group_key,
            'wd_count' => (int) $r->wd_count,
            'sum_amount' => (int) $r->sum_amount,
            'sum_net_amount' => (int) $r->sum_net_amount,
            'sum_fee' => (int) $r->sum_fee,
        ])->all()];
    }

    private function toolQueryTickets(User $ma, array $input): array
    {
        $query = MerchantTicket::query()->whereIn('merchant_id', $this->scope->merchantIds($ma));

        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (! empty($input['department'])) {
            $query->where('department', $input['department']);
        }
        if (! empty($input['category'])) {
            $query->where('category', $input['category']);
        }
        if (! empty($input['merchant_name'])) {
            $query->whereHas('merchant', fn ($q) => $q->where('name', 'like', '%'.$input['merchant_name'].'%'));
        }
        $this->applyDateRange($query, $input, 'created_at');

        if (($input['mode'] ?? 'summary') === 'list') {
            $limit = min(50, max(1, (int) ($input['limit'] ?? 10)));
            $rows = $query->with('merchant:id,name')->latest('created_at')->limit($limit)->get();

            return ['tickets' => $rows->map(fn ($t) => [
                'ticket_no' => $t->ticket_no,
                'merchant' => $t->merchant?->name,
                'department' => $t->department,
                'category' => $t->category,
                'status' => $t->status,
                'created_at_wib' => $t->created_at?->timezone('Asia/Jakarta')->toDateTimeString(),
            ])->all()];
        }

        $groupBy = $input['group_by'] ?? 'none';
        $groupExpr = match ($groupBy) {
            'status' => 'status',
            'department' => 'department',
            'category' => 'category',
            'merchant' => 'merchant_id',
            default => "'all'",
        };

        $rows = $query
            ->selectRaw("{$groupExpr} as group_key")
            ->selectRaw('COUNT(*) as ticket_count')
            ->groupByRaw($groupExpr)
            ->orderByDesc('ticket_count')
            ->limit(50)
            ->get();

        $merchantNames = $groupBy === 'merchant'
            ? Merchant::query()->whereIn('id', $rows->pluck('group_key'))->pluck('name', 'id')
            : collect();

        return ['summary' => $rows->map(fn ($r) => [
            'group' => $groupBy === 'merchant' ? ($merchantNames[$r->group_key] ?? $r->group_key) : $r->group_key,
            'ticket_count' => (int) $r->ticket_count,
        ])->all()];
    }

    /**
     * Builds a per-request set of temporary tables containing ONLY this MA's own
     * rows, computed server-side from CsScopeResolver (never from AI input). The
     * run_sql tool is only ever allowed to query these - never the real tables -
     * so no AI-written SQL, however it's phrased, can reach another MA's data:
     * the rows simply don't exist in these tables to begin with.
     */
    private function ensureSqlScope(User $ma): void
    {
        if ($this->sqlScopeReady) {
            return;
        }

        $ids = $this->scope->merchantIds($ma);
        $idList = implode(',', array_map('intval', $ids) ?: [0]);

        // SQLite (used by the test suite) has no "DROP TEMPORARY TABLE" syntax -
        // plain DROP TABLE already targets the temp one there. MySQL/MariaDB get
        // the more specific form so this can never touch a permanent table.
        $dropKeyword = DB::connection()->getDriverName() === 'sqlite' ? 'TABLE' : 'TEMPORARY TABLE';
        DB::statement("DROP {$dropKeyword} IF EXISTS my_merchants");
        DB::statement("DROP {$dropKeyword} IF EXISTS my_transactions");
        DB::statement("DROP {$dropKeyword} IF EXISTS my_withdrawals");
        DB::statement("DROP {$dropKeyword} IF EXISTS my_tickets");

        DB::statement("
            CREATE TEMPORARY TABLE my_merchants AS
            SELECT id, name, merchant_type, approval_status, gateway,
                   merchant_mdr_percent, ma_fee_percent, agent_fee_percent, created_at
            FROM merchants WHERE id IN ({$idList})
        ");

        DB::statement("
            CREATE TEMPORARY TABLE my_transactions AS
            SELECT t.id, t.merchant_id, m.name AS merchant_name, t.status, t.amount,
                   t.net_amount, t.fee_amount, t.submitted_at, t.rrn
            FROM topup_requests t JOIN merchants m ON m.id = t.merchant_id
            WHERE t.merchant_id IN ({$idList})
        ");

        DB::statement("
            CREATE TEMPORARY TABLE my_withdrawals AS
            SELECT w.id, w.merchant_id, m.name AS merchant_name, w.status, w.amount,
                   w.net_amount, w.fee, w.gateway_created_at, w.gateway_completed_at
            FROM merchant_withdrawals w JOIN merchants m ON m.id = w.merchant_id
            WHERE w.merchant_id IN ({$idList})
        ");

        DB::statement("
            CREATE TEMPORARY TABLE my_tickets AS
            SELECT tk.id, tk.merchant_id, m.name AS merchant_name, tk.ticket_no, tk.department,
                   tk.category, tk.status, tk.created_at, tk.closed_at
            FROM merchant_tickets tk JOIN merchants m ON m.id = tk.merchant_id
            WHERE tk.merchant_id IN ({$idList})
        ");

        $this->sqlScopeReady = true;
    }

    private function toolRunSql(User $ma, array $input): array
    {
        $sql = trim((string) ($input['sql'] ?? ''));
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }

        $reason = $this->rejectUnsafeSql($sql);
        if ($reason) {
            return ['error' => $reason];
        }

        $this->ensureSqlScope($ma);

        try {
            $rows = DB::select("SELECT * FROM ({$sql}) AS sql_result LIMIT 200");
        } catch (\Throwable $e) {
            return ['error' => 'Query gagal dijalankan: '.$e->getMessage()];
        }

        return ['rows' => $rows, 'row_count' => count($rows)];
    }

    private function rejectUnsafeSql(string $sql): ?string
    {
        if (str_contains($sql, ';')) {
            return 'Cuma boleh 1 statement (nggak boleh pakai titik koma).';
        }

        if (! preg_match('/^SELECT\s/i', $sql)) {
            return 'Cuma boleh pakai SELECT.';
        }

        if (preg_match('/select\s+(distinct\s+)?\*/i', $sql) || preg_match('/\w+\.\*/', $sql)) {
            return 'Kolom wildcard (SELECT *) nggak boleh - sebutkan kolom yang dibutuhkan satu-satu.';
        }

        $forbidden = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'GRANT', 'REVOKE',
            'CREATE', 'REPLACE', 'CALL', 'EXEC', 'EXECUTE', 'LOCK', 'UNLOCK', 'SET', 'LOAD',
            'OUTFILE', 'DUMPFILE', 'BENCHMARK', 'SLEEP',
            'password', 'plain_password', 'secondary_password', 'merchant_key',
        ];
        foreach ($forbidden as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $sql)) {
                return "Query mengandung keyword yang nggak diizinkan: {$keyword}.";
            }
        }

        preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?/i', $sql, $matches);
        $referenced = array_map('strtolower', $matches[1] ?? []);
        $allowedTables = ['my_merchants', 'my_transactions', 'my_withdrawals', 'my_tickets'];

        if ($referenced === []) {
            return 'Query harus punya FROM ke salah satu tabel: '.implode(', ', $allowedTables).'.';
        }

        foreach ($referenced as $table) {
            if (! in_array($table, $allowedTables, true)) {
                return "Tabel '{$table}' tidak ada/tidak diizinkan. Tabel yang bisa dipakai: ".implode(', ', $allowedTables).'.';
            }
        }

        return null;
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
            [
                'name' => 'query_transactions',
                'description' => 'Query fleksibel transaksi toko MA ini - list transaksi mentah atau ringkasan agregat (jumlah & total nominal), dengan filter status/toko/tanggal dan pengelompokan. Pakai ini buat pertanyaan transaksi yang nggak kecover tool lain, misal "volume per toko minggu ini", "transaksi gagal bulan lalu berapa", "tren harian 7 hari terakhir".',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'mode' => ['type' => 'STRING', 'enum' => ['summary', 'list'], 'description' => 'summary = agregat (default), list = daftar transaksi mentah'],
                        'group_by' => ['type' => 'STRING', 'enum' => ['none', 'status', 'merchant', 'day'], 'description' => 'Hanya dipakai saat mode=summary, default none'],
                        'status' => ['type' => 'STRING', 'description' => 'Filter status: pending/success/expired/failed/rejected'],
                        'merchant_name' => ['type' => 'STRING', 'description' => 'Filter sebagian nama toko'],
                        'date_from' => ['type' => 'STRING', 'description' => 'Tanggal mulai, format YYYY-MM-DD (WIB)'],
                        'date_to' => ['type' => 'STRING', 'description' => 'Tanggal akhir, format YYYY-MM-DD (WIB)'],
                        'limit' => ['type' => 'INTEGER', 'description' => 'Hanya dipakai saat mode=list, default 10 maksimal 50'],
                    ],
                ],
            ],
            [
                'name' => 'query_withdrawals',
                'description' => 'Query fleksibel withdrawal/disbursement toko MA ini - list mentah atau ringkasan agregat, dengan filter status/toko/tanggal dan pengelompokan. Pakai buat pertanyaan withdrawal yang nggak kecover tool lain.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'mode' => ['type' => 'STRING', 'enum' => ['summary', 'list']],
                        'group_by' => ['type' => 'STRING', 'enum' => ['none', 'status', 'merchant', 'day']],
                        'status' => ['type' => 'STRING', 'description' => 'Filter status withdrawal dari gateway'],
                        'merchant_name' => ['type' => 'STRING', 'description' => 'Filter sebagian nama toko'],
                        'date_from' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD (WIB)'],
                        'date_to' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD (WIB)'],
                        'limit' => ['type' => 'INTEGER', 'description' => 'Hanya dipakai saat mode=list, default 10 maksimal 50'],
                    ],
                ],
            ],
            [
                'name' => 'query_tickets',
                'description' => 'Query fleksibel tiket toko MA ini - list mentah atau ringkasan jumlah tiket, dengan filter status/department/category/toko/tanggal dan pengelompokan. Pakai buat pertanyaan tiket yang nggak kecover tool lain, misal "tiket open berapa", "breakdown tiket per kategori".',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'mode' => ['type' => 'STRING', 'enum' => ['summary', 'list']],
                        'group_by' => ['type' => 'STRING', 'enum' => ['none', 'status', 'department', 'category', 'merchant']],
                        'status' => ['type' => 'STRING', 'description' => 'open/in_progress/closed'],
                        'department' => ['type' => 'STRING', 'description' => 'cs/tech'],
                        'category' => ['type' => 'STRING'],
                        'merchant_name' => ['type' => 'STRING', 'description' => 'Filter sebagian nama toko'],
                        'date_from' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD (WIB)'],
                        'date_to' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD (WIB)'],
                        'limit' => ['type' => 'INTEGER', 'description' => 'Hanya dipakai saat mode=list, default 10 maksimal 50'],
                    ],
                ],
            ],
            [
                'name' => 'run_sql',
                'description' => "Jalankan query SQL SELECT bebas buat jawab pertanyaan data toko MA ini yang nggak kecover tool lain. Cuma bisa akses 4 tabel yang udah otomatis di-scope ke toko MA ini (nggak ada data MA lain yang bisa kelihatan lewat tool ini):\n- my_merchants (id, name, merchant_type, approval_status, gateway, merchant_mdr_percent, ma_fee_percent, agent_fee_percent, created_at)\n- my_transactions (id, merchant_id, merchant_name, status, amount, net_amount, fee_amount, submitted_at, rrn)\n- my_withdrawals (id, merchant_id, merchant_name, status, amount, net_amount, fee, gateway_created_at, gateway_completed_at)\n- my_tickets (id, merchant_id, merchant_name, ticket_no, department, category, status, created_at, closed_at)\nCuma SELECT, 1 statement, nggak boleh SELECT * (sebutkan kolom), nggak bisa JOIN ke tabel lain selain 4 di atas. Coba tool lain dulu kalau udah ada yang pas - pakai ini buat query custom/kompleks yang belum kecover.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'sql' => ['type' => 'STRING', 'description' => 'Query SQL SELECT, cuma dari 4 tabel yang diizinkan (my_merchants/my_transactions/my_withdrawals/my_tickets).'],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ];
    }
}
