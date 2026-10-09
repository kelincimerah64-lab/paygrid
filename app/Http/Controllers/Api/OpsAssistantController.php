<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\FeeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Narrow, token-authenticated API for the restricted "ops assistant" Claude
 * Code session (a separate repo with locked-down tool permissions - see
 * ops-assistant/CLAUDE.md) - the only two things that session is allowed to
 * do: create a merchant, and read summary data to answer questions. It must
 * never gain any other capability (editing PayGrid's own code, SSH, etc.) -
 * that boundary is enforced by the calling session's permissions, not here,
 * so keep this controller itself narrow and safe regardless.
 */
class OpsAssistantController extends Controller
{
    private function authorize_(Request $request): void
    {
        $token = (string) config('services.ops_assistant.token');
        $provided = (string) $request->bearerToken();
        abort_unless($token !== '' && hash_equals($token, $provided), 401);
    }

    public function createMerchant(Request $request, AuditLogService $audit, FeeSyncService $feeSync): JsonResponse
    {
        $this->authorize_($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
            'agent_name' => ['nullable', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'cs_email' => ['nullable', 'email', 'max:255'],
            'gateway' => ['nullable', Rule::in(['hilogate', 'artageto', 'alpha', 'kingspay'])],
            'merchant_type' => ['nullable', Rule::in(['cm', 'script'])],
            'engine_type' => ['nullable', Rule::in(['sc', 'api'])],
            'merchant_id' => ['nullable', 'string', 'max:160'],
            'merchant_key' => ['nullable', 'string', 'max:255'],
            'fee_menu' => ['nullable', 'string'],
            'merchant_mdr_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'disbursement_fee_fixed' => ['nullable', 'integer', 'min:0'],
        ]);

        if (empty($data['agent_id']) && empty($data['agent_name'])) {
            return response()->json(['error' => 'Sertakan agent_id atau agent_name.'], 422);
        }

        if (! empty($data['agent_id'])) {
            $agent = Agent::query()->with('ma')->find($data['agent_id']);
        } else {
            $matches = Agent::query()->with('ma')->where('name', 'like', '%'.$data['agent_name'].'%')->get();
            if ($matches->count() !== 1) {
                return response()->json([
                    'error' => $matches->isEmpty()
                        ? 'Tidak ada agen yang cocok dengan nama tersebut.'
                        : 'Lebih dari satu agen cocok, sebutkan lebih spesifik atau pakai agent_id.',
                    'candidates' => $matches->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'code' => $a->code])->all(),
                ], 422);
            }
            $agent = $matches->first();
        }

        $gateway = $data['gateway'] ?? 'hilogate';
        $merchantType = $data['merchant_type'] ?? 'script';
        $engineType = $data['engine_type'] ?? ($merchantType === 'script' ? 'sc' : null);
        $feeMenu = $data['fee_menu'] ?? 'everyday_sc';

        $rates = array_fill_keys(['h_plus_1', 'everyday_api', 'same_day_api', 'cm_H1', 'h_plus_1_sc', 'everyday_sc', 'same_day_sc', 'cm_everyday'], 0);
        $rates[$feeMenu] = (float) $data['merchant_mdr_percent'];

        $base = Str::slug($data['name']) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;
        while (Merchant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        $settlementMethod = str_contains($feeMenu, 'same_day') ? 'same_day' : (str_contains($feeMenu, 'h_plus_1') ? 'h_plus_1' : 'everyday');

        $merchant = Merchant::query()->create([
            'agent_id' => $agent->id,
            'slug' => $slug,
            'name' => $data['name'],
            'merchant_id' => $data['merchant_id'] ?? null,
            'merchant_key' => $data['merchant_key'] ?? null,
            'merchant_group_name' => $agent->name,
            'merchant_group_id' => $agent->hg_group_id,
            'merchant_type' => $merchantType,
            'engine_type' => $engineType,
            'gateway' => $gateway,
            'approval_status' => 'approved',
            'topup_enabled' => $merchantType === 'cm',
            'topup_url' => $merchantType === 'cm' ? route('topup', ['merchant' => $slug]) : null,
            'transaction_callback_url' => url('/api/callbacks/hilogate/transaction'),
            'cs_email' => $data['cs_email'] ?? $data['admin_email'],
            'fee_menu' => $feeMenu,
            'fee_menu_rates' => $rates,
            'settlement_method' => $settlementMethod,
            ...$feeSync->snapshotFor($agent, $feeMenu, (float) $data['merchant_mdr_percent']),
            'disbursement_fee_fixed' => $data['disbursement_fee_fixed'] ?? null,
            'onboarding_payload' => $data,
            'approved_at' => now(),
        ]);

        $admin = User::query()->create([
            'name' => str($data['admin_email'])->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
            'email' => $data['admin_email'],
            'role' => 'admin',
            'merchant_id' => $merchant->id,
            'password' => Hash::make(config('paygrid.demo_password')),
            'plain_password' => config('paygrid.demo_password'),
        ]);

        $audit->record('ops_assistant.merchant_created', $merchant, null, $merchant->only(['slug', 'name', 'agent_id', 'gateway', 'merchant_type']));

        return response()->json([
            'ok' => true,
            'merchant' => [
                'id' => $merchant->id,
                'slug' => $merchant->slug,
                'name' => $merchant->name,
                'agent' => $agent->name,
                'fee_menu' => $merchant->fee_menu,
                'merchant_mdr_percent' => (float) $merchant->merchant_mdr_percent,
                'agent_fee_percent' => (float) $merchant->agent_fee_percent,
                'ma_fee_percent' => (float) $merchant->ma_fee_percent,
                'settlement_method' => $merchant->settlement_method,
            ],
            'admin_login' => ['email' => $admin->email, 'password' => config('paygrid.demo_password')],
        ]);
    }

    public function listMerchants(Request $request): JsonResponse
    {
        $this->authorize_($request);

        $rows = Merchant::query()
            ->with('agent:id,name')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->query('search').'%'))
            ->when($request->filled('agent_name'), fn ($q) => $q->whereHas('agent', fn ($aq) => $aq->where('name', 'like', '%'.$request->query('agent_name').'%')))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'agent_id', 'merchant_type', 'approval_status', 'gateway', 'merchant_mdr_percent']);

        return response()->json(['merchants' => $rows->map(fn ($m) => [
            'name' => $m->name,
            'agent' => $m->agent?->name,
            'type' => $m->merchant_type,
            'status' => $m->approval_status,
            'gateway' => $m->gateway,
            'merchant_mdr_percent' => (float) $m->merchant_mdr_percent,
        ])->all()]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize_($request);

        $period = $request->query('period', 'today');
        [$from, $to] = match ($period) {
            'this_month' => [now('Asia/Jakarta')->startOfMonth(), now('Asia/Jakarta')],
            'last_30_days' => [now('Asia/Jakarta')->subDays(29)->startOfDay(), now('Asia/Jakarta')],
            default => [now('Asia/Jakarta')->startOfDay(), now('Asia/Jakarta')],
        };

        $row = TopupRequest::query()
            ->whereBetween('submitted_at', [$from->utc(), $to->utc()])
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as trx_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume_success")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as trx_pending")
            ->first();

        $topMerchants = TopupRequest::query()
            ->whereBetween('submitted_at', [$from->utc(), $to->utc()])
            ->where('status', 'success')
            ->selectRaw('merchant_id, COUNT(*) as trx_total, SUM(amount) as volume')
            ->groupBy('merchant_id')
            ->orderByDesc('volume')
            ->limit(5)
            ->with('merchant:id,name')
            ->get()
            ->map(fn ($r) => ['name' => $r->merchant?->name, 'trx_total' => (int) $r->trx_total, 'volume' => (int) $r->volume]);

        return response()->json([
            'period' => $period,
            'trx_success' => (int) $row->trx_success,
            'volume_success_gross' => (int) $row->volume_success,
            'trx_pending' => (int) $row->trx_pending,
            'top_merchants' => $topMerchants,
            'merchant_count' => Merchant::query()->where('approval_status', 'approved')->count(),
            'agent_count' => Agent::query()->where('is_active', true)->count(),
        ]);
    }

    /**
     * Read-only SQL escape hatch so the ops assistant can answer any
     * question (tickets, approvals, IP-whitelist history, etc.) without a
     * bespoke endpoint for every table. Deliberately restricted to a single
     * SELECT with no wildcard columns and no sensitive-credential columns,
     * so it can never be used to write data or exfiltrate secrets even if
     * the assistant is tricked into trying.
     */
    public function query(Request $request): JsonResponse
    {
        $this->authorize_($request);

        $data = $request->validate([
            'sql' => ['required', 'string', 'max:2000'],
        ]);

        $sql = trim($data['sql']);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }

        $reason = $this->rejectUnsafeSelect($sql);
        if ($reason) {
            return response()->json(['error' => $reason], 422);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                DB::statement('SET SESSION MAX_EXECUTION_TIME=5000');
            } catch (\Throwable $e) {
                // Best-effort timeout guard only - MariaDB doesn't support this variable.
            }
        }

        try {
            $rows = DB::select($sql);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Query failed: '.$e->getMessage()], 422);
        }

        $truncated = count($rows) > 200;
        $rows = array_slice($rows, 0, 200);

        return response()->json(['rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated]);
    }

    private function rejectUnsafeSelect(string $sql): ?string
    {
        if (str_contains($sql, ';')) {
            return 'Only a single statement is allowed (no semicolons).';
        }

        if (! preg_match('/^SELECT\s/i', $sql)) {
            return 'Only SELECT statements are allowed.';
        }

        if (preg_match('/select\s+(distinct\s+)?\*/i', $sql) || preg_match('/\w+\.\*/', $sql)) {
            return 'Wildcard columns (SELECT *) are not allowed - list the columns you need explicitly.';
        }

        $forbidden = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'GRANT', 'REVOKE',
            'CREATE', 'REPLACE', 'CALL', 'EXEC', 'EXECUTE', 'LOCK', 'UNLOCK', 'SET', 'LOAD',
            'OUTFILE', 'DUMPFILE', 'BENCHMARK', 'SLEEP',
            'password', 'plain_password', 'secondary_password', 'hilogate_onboarding_password',
            'remember_token', 'merchant_key',
        ];
        foreach ($forbidden as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $sql)) {
                return "Query references a disallowed keyword or column: {$keyword}.";
            }
        }

        return null;
    }
}
