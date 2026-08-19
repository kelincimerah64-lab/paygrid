<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantGatewayBalance;
use App\Models\MerchantRegistration;
use App\Models\MerchantSettlement;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MaController extends Controller
{
    public function page(string $page = 'overview'): View
    {
        abort_unless(in_array($page, ['overview', 'report', 'fee', 'approval', 'mapping', 'stores', 'agents', 'create-store'], true), 404);

        $filters = $this->filters();
        $dataFilters = in_array($page, ['overview', 'report', 'fee'], true) ? $this->periodFilters($filters) : $filters;
        $agents = $this->agents($filters)->get();
        $merchants = $this->merchants($filters)->get();
        $registrations = $this->registrations($filters)->get();
        $transactions = $page === 'report' && $dataFilters['store_id'] === 'all'
            ? null
            : $this->transactions($dataFilters)->simplePaginate($page === 'report' ? 25 : config('paygrid.reports.default_page_size', 50))->withQueryString();

        return view('paygrid.ma', [
            'roleLabel' => 'MA',
            'menus' => app(\App\Services\Navigation\MenuBuilder::class)->ma(),
            'active' => $page,
            'title' => match ($page) {
                'report' => 'Report',
                'fee' => 'Fee',
                'approval' => 'Request Approval',
                'mapping' => 'Mapping Agen',
                'stores' => 'List Toko',
                'agents' => 'Agen',
                'create-store' => 'Create Toko',
                default => 'Overview',
            },
            'filters' => $filters,
            'dataFilters' => $dataFilters,
            'periodLabel' => $this->periodLabel($dataFilters),
            'agents' => $agents,
            'allAgents' => $this->agents($this->blankFilters())->get(),
            'selectedAgent' => $this->selectedAgent($filters),
            'selectedStore' => $this->selectedStore($filters),
            'selectedAgentStores' => $this->selectedAgentStores($filters),
            'merchants' => $merchants,
            'registrations' => $registrations,
            'transactions' => $transactions,
            'selectedStoreStats' => $dataFilters['store_id'] === 'all' ? null : $this->selectedStoreStats($dataFilters),
            'summary' => $this->summary($dataFilters),
            'overviewDetails' => $this->overviewDetails($dataFilters),
            'reportAgents' => $this->reportAgents($dataFilters),
            'storeSummaries' => $this->storeSummaries($dataFilters),
            'storeRanking' => $this->storeRanking($dataFilters),
            'agentRanking' => $this->agentRanking($dataFilters),
            'maNotifications' => request()->user()?->unreadNotifications()->latest()->limit(5)->get() ?? collect(),
        ]);
    }

    public function export(Request $request): Response
    {
        $rows = $this->transactions($this->filters())->limit(5000)->get();
        $csv = "Masuk,Sukses,Durasi,Toko,Agen,Status,Amount,Reference,RRN,Payment ID,Net,Settlement\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', [
                $row->submitted_at?->format('Y-m-d H:i:s'),
                $row->succeeded_at?->format('Y-m-d H:i:s'),
                $row->successDurationLabel(),
                $row->merchant?->name,
                $row->merchant?->agent?->name,
                $row->status,
                $row->amount,
                $row->customer_reference ?: $row->gateway_ref_id,
                $row->rrn,
                $row->payment_id,
                $row->net_amount,
                $row->status === 'success' ? 'settleable' : 'pending',
            ]))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ma-report.csv"',
        ]);
    }

    public function storeAgent(Request $request, AuditLogService $audit): RedirectResponse
    {
        $this->normalizePercentInputs($request, ['default_agent_fee_percent']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'contact' => ['nullable', 'string', 'max:80'],
            'status' => ['required', 'in:Active,Review,Suspended'],
            'default_agent_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'password' => ['nullable', 'string', 'min:6', 'max:120'],
        ]);
        abort_if(config('paygrid.gateway.hilogate.agent_create_enabled'), 423, 'Create agen ke HG masih dinonaktifkan.');
        $password = $data['password'] ?: config('paygrid.demo_password');
        $code = $this->uniqueAgentCode($data['name']);
        $isActive = $data['status'] === 'Active';
        $agent = Agent::query()->create([
            'ma_user_id' => $this->currentMaId(),
            'code' => $code,
            'name' => $data['name'],
            'email' => $data['email'],
            'contact' => $data['contact'] ?? null,
            'hg_group_id' => null,
            'default_agent_fee_percent' => $data['default_agent_fee_percent'],
            'is_active' => $isActive,
            'password_plain' => $password,
        ]);
        $agentUser = User::query()->create([
            'name' => $agent->name,
            'email' => $agent->email,
            'username' => $agent->code,
            'role' => 'agent',
            'is_active' => $isActive,
            'password' => Hash::make($password),
            'plain_password' => $password,
        ]);
        $audit->record('ma.agent_created', $agent, null, $agent->toArray());
        $audit->record('ma.agent_account_created', $agentUser, null, $agentUser->only(['email', 'username', 'role']));

        return back()->with('status', 'Agen lokal berhasil dibuat. Kode login: '.$agent->code.'. Password: '.$password.'. Belum dikirim ke HG.');
    }

    public function mapAgent(Request $request, Merchant $merchant, AuditLogService $audit): RedirectResponse
    {
        abort_unless($this->canUseMerchant($merchant), 403);
        $data = $request->validate(['agent_id' => ['required', 'exists:agents,id']]);
        $agent = Agent::query()->findOrFail($data['agent_id']);
        abort_unless($this->canUseAgent($agent), 403);
        $before = $merchant->only(['agent_id', 'agent_fee_percent', 'ma_fee_percent', 'merchant_mdr_percent']);
        $baseCost = (float) $merchant->base_mdr_percent + (float) $merchant->connection_fee_percent + (float) $merchant->settlement_fee_percent;
        $agentFee = (float) $agent->default_agent_fee_percent;
        $merchantMdr = max((float) $merchant->merchant_mdr_percent, $baseCost + $agentFee);
        $merchant->forceFill([
            'agent_id' => $agent->id,
            'merchant_group_name' => $agent->name,
            'merchant_group_id' => $agent->hg_group_id,
            'agent_fee_percent' => $agentFee,
            'merchant_mdr_percent' => $merchantMdr,
            'ma_fee_percent' => max(0, $merchantMdr - $baseCost - $agentFee),
        ])->save();
        $audit->record('ma.merchant_agent_mapped', $merchant, $before, $merchant->only(array_keys($before)));

        return back()->with('status', 'Agen toko berhasil disimpan.');
    }

    public function storeMerchant(Request $request, AuditLogService $audit): RedirectResponse
    {
        $this->normalizePercentInputs($request, ['merchant_mdr_percent', 'base_mdr_percent', 'connection_fee_percent', 'settlement_fee_percent', 'agent_fee_percent', 'toko_fee_percent']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['nullable', 'string', 'max:80'],
            'engine_name' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['required', 'exists:agents,id'],
            'pic_email' => ['nullable', 'email', 'max:160'],
            'pic_telegram' => ['nullable', 'string', 'max:80'],
            'admin_email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:80'],
            'environment' => ['required', 'in:Sandbox,Production'],
            'gateway' => ['required', 'in:hilogate,artageto,alpha,kingspay'],
            'merchant_type' => ['required', 'in:cm,script'],
            'merchant_id' => ['nullable', 'string', 'max:160'],
            'merchant_key' => ['nullable', 'string', 'max:255'],
            'transaction_callback_url' => ['nullable', 'url', 'max:255'],
            'withdrawal_callback_url' => ['nullable', 'url', 'max:255'],
            'api_ip_whitelist' => ['nullable', 'string', 'max:255'],
            'settlement_method' => ['required', 'in:standard_h1,everyday_1x,sameday_3x,h_plus_1,everyday,same_day'],
            'merchant_mdr_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'base_mdr_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'connection_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'settlement_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'agent_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'toko_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $baseCost = (float) $data['base_mdr_percent'] + (float) $data['connection_fee_percent'] + (float) $data['settlement_fee_percent'];
        abort_if((float) $data['merchant_mdr_percent'] < $baseCost + (float) $data['agent_fee_percent'], 422, 'Merchant fee minimal harus >= Base Cost + Agent Fee.');
        $slug = $this->uniqueMerchantSlug($data['name']);
        $agent = Agent::query()->findOrFail($data['agent_id']);
        abort_unless($this->canUseAgent($agent), 403);
        $merchant = Merchant::query()->create([
            'agent_id' => $agent->id,
            'slug' => $slug,
            'name' => $data['name'],
            'merchant_id' => $data['merchant_id'] ?? null,
            'merchant_key' => $data['merchant_key'] ?? null,
            'merchant_group_name' => $agent->name,
            'merchant_group_id' => $agent->hg_group_id,
            'merchant_type' => $data['merchant_type'],
            'gateway' => $data['gateway'],
            'approval_status' => 'approved',
            'topup_enabled' => $data['merchant_type'] === 'cm',
            'topup_url' => $data['merchant_type'] === 'cm' ? route('topup', ['merchant' => $slug]) : null,
            'transaction_callback_url' => $data['transaction_callback_url'] ?? url('/api/callbacks/hilogate/transaction'),
            'withdrawal_callback_url' => $data['withdrawal_callback_url'] ?? null,
            'pic_email' => $data['pic_email'] ?? null,
            'pic_telegram' => $data['pic_telegram'] ?? null,
            'merchant_mdr_percent' => $data['merchant_mdr_percent'],
            'base_mdr_percent' => $data['base_mdr_percent'],
            'connection_fee_percent' => $data['connection_fee_percent'],
            'settlement_method' => $data['settlement_method'],
            'settlement_fee_percent' => $data['settlement_fee_percent'],
            'agent_fee_percent' => $data['agent_fee_percent'],
            'toko_fee_percent' => $data['toko_fee_percent'] ?? 0,
            'ma_fee_percent' => max(0, (float) $data['merchant_mdr_percent'] - $baseCost - (float) $data['agent_fee_percent']),
            'onboarding_payload' => $data + ['api_ip_whitelist' => $data['api_ip_whitelist'] ?: '15.232.137.74'],
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
        $audit->record('ma.merchant_created', $merchant, null, $merchant->only(['slug', 'name', 'agent_id', 'gateway', 'merchant_type']));
        $audit->record('ma.merchant_admin_created', $admin, null, $admin->only(['email', 'role', 'merchant_id']));

        return back()->with('status', 'Toko berhasil dibuat. Admin default: '.$admin->email.' / '.config('paygrid.demo_password').'.');
    }

    public function updateStoreFee(Request $request, Merchant $merchant, AuditLogService $audit): RedirectResponse
    {
        abort_unless($this->canUseMerchant($merchant), 403);
        $this->normalizePercentInputs($request, ['merchant_mdr_percent', 'base_mdr_percent', 'payin_fee_percent', 'settlement_fee_percent', 'agent_fee_percent']);
        $data = $request->validate([
            'merchant_mdr_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'base_mdr_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'payin_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'settlement_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'agent_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $cost = (float) $data['base_mdr_percent'] + (float) $data['payin_fee_percent'] + (float) $data['settlement_fee_percent'] + (float) $data['agent_fee_percent'];
        if ((float) $data['merchant_mdr_percent'] < $cost) {
            return back()->withErrors(['fee' => 'Merchant MDR minimal harus >= Base HG + Pay In + Settlement + Agent.'])->withInput();
        }

        $data['connection_fee_percent'] = $data['payin_fee_percent'];
        $data['ma_fee_percent'] = max(0, (float) $data['merchant_mdr_percent'] - $cost);
        $before = $merchant->only(['merchant_mdr_percent', 'base_mdr_percent', 'payin_fee_percent', 'connection_fee_percent', 'settlement_fee_percent', 'ma_fee_percent', 'agent_fee_percent']);
        $merchant->forceFill($data)->save();
        $audit->record('ma.merchant_fee_updated', $merchant, $before, $merchant->only(array_keys($before)));

        return back()->with('status', 'Fee toko berhasil disimpan.');
    }

    private function filters(): array
    {
        return [
            'q' => trim((string) request('q', '')),
            'status' => (string) request('status', 'all'),
            'agent_id' => (string) request('agent_id', 'all'),
            'store_id' => (string) request('store_id', 'all'),
            'agents_view' => (string) request('agents_view', 'top'),
            'period' => (string) request('period', 'this_month'),
            'type' => (string) request('type', 'all'),
            'from' => request('from'),
            'to' => request('to'),
        ];
    }

    private function blankFilters(): array
    {
        return ['q' => '', 'status' => 'all', 'agent_id' => 'all', 'store_id' => 'all', 'agents_view' => 'top', 'period' => 'this_month', 'type' => 'all', 'from' => null, 'to' => null];
    }

    private function periodFilters(array $filters): array
    {
        $now = now('Asia/Jakarta');
        [$from, $to] = match ($filters['period']) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth()->addSecond(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now],
            'custom' => [
                $filters['from'] ? CarbonImmutable::parse($filters['from'], 'Asia/Jakarta')->startOfDay() : $now->copy()->startOfMonth()->addSecond(),
                $filters['to'] ? CarbonImmutable::parse($filters['to'], 'Asia/Jakarta')->endOfDay() : $now,
            ],
            default => [$now->copy()->startOfMonth()->addSecond(), $now],
        };

        if ($filters['from'] || $filters['to']) {
            $from = $filters['from'] ? CarbonImmutable::parse($filters['from'], 'Asia/Jakarta')->startOfDay() : $from;
            $to = $filters['to'] ? CarbonImmutable::parse($filters['to'], 'Asia/Jakarta')->endOfDay() : $to;
        }

        return array_merge($filters, [
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
        ]);
    }

    private function periodLabel(array $filters): string
    {
        if (! $filters['from'] && ! $filters['to']) {
            return 'Semua periode';
        }

        $from = $filters['from'] ? CarbonImmutable::parse($filters['from'], 'Asia/Jakarta')->translatedFormat('d M Y H:i:s') : 'awal';
        $to = $filters['to'] ? CarbonImmutable::parse($filters['to'], 'Asia/Jakarta')->translatedFormat('d M Y') : 'hari ini';

        return $from.' - '.$to.' WIB';
    }

    private function merchants(array $filters)
    {
        return Merchant::query()->with('agent')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', $filters['q'].'%')->orWhere('slug', 'like', $filters['q'].'%')->orWhere('merchant_id', 'like', $filters['q'].'%')->orWhereRelation('agent', 'name', 'like', $filters['q'].'%')))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('approval_status', $filters['status']))
            ->when($filters['agent_id'] !== 'all', fn ($query) => $query->where('agent_id', $filters['agent_id']))
            ->when($filters['store_id'] !== 'all', fn ($query) => $query->whereKey($filters['store_id']))
            ->when($filters['type'] !== 'all', fn ($query) => $query->where('merchant_type', $filters['type']))
            ->orderBy('name');
    }

    private function agents(array $filters)
    {
        return Agent::query()
            ->when($this->currentMaId(), fn ($query, $maId) => $query->where('ma_user_id', $maId))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', $filters['q'].'%')->orWhere('email', 'like', $filters['q'].'%')->orWhere('code', 'like', $filters['q'].'%')))
            ->when($filters['status'] === 'Active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'Suspended', fn ($query) => $query->where('is_active', false))
            ->orderBy('name');
    }

    private function registrations(array $filters)
    {
        return MerchantRegistration::query()->with('agent')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('store_name', 'like', $filters['q'].'%')->orWhere('engine_name', 'like', $filters['q'].'%')->orWhereRelation('agent', 'name', 'like', $filters['q'].'%')))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->orderByRaw("CASE WHEN status IN ('pending', 'pending_agent', 'pending_ma') THEN 0 WHEN status = 'approved' THEN 1 WHEN status = 'rejected' THEN 2 ELSE 3 END")
            ->latest();
    }

    private function transactions(array $filters)
    {
        return TopupRequest::query()->with('merchant.agent')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->whereRelation('merchant.agent', 'ma_user_id', $maId))
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('topup_requests.payment_id', 'like', $filters['q'].'%')->orWhere('topup_requests.rrn', 'like', $filters['q'].'%')->orWhere('topup_requests.customer_reference', 'like', $filters['q'].'%')->orWhereRelation('merchant', 'name', 'like', $filters['q'].'%')))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('topup_requests.status', $filters['status']))
            ->when($filters['agent_id'] !== 'all', fn ($query) => $query->whereRelation('merchant', 'agent_id', $filters['agent_id']))
            ->when($filters['store_id'] !== 'all', fn ($query) => $query->where('topup_requests.merchant_id', $filters['store_id']))
            ->latest('topup_requests.submitted_at');
    }

    private function selectedAgent(array $filters): ?Agent
    {
        if ($filters['agent_id'] === 'all') {
            return null;
        }

        $agent = Agent::query()->find($filters['agent_id']);

        return $agent && $this->canUseAgent($agent) ? $agent : null;
    }

    private function selectedStore(array $filters): ?Merchant
    {
        if ($filters['store_id'] === 'all') {
            return null;
        }

        $merchant = Merchant::query()->with('agent')->find($filters['store_id']);

        return $merchant && $this->canUseMerchant($merchant) ? $merchant : null;
    }

    private function selectedAgentStores(array $filters)
    {
        if ($filters['agent_id'] === 'all') {
            return collect();
        }

        $stores = $this->merchants(array_merge($filters, ['store_id' => 'all']))->get();
        $totals = TopupRequest::query()
            ->whereIn('merchant_id', $stores->pluck('id'))
            ->where('status', 'success')
            ->when($filters['from'], fn ($query) => $query->where('submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('merchant_id, COUNT(*) as trx_total')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume_success')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        return $stores
            ->each(function (Merchant $store) use ($totals): void {
                $row = $totals->get($store->id);
                $store->metric_trx_total = (int) ($row?->trx_total ?? 0);
                $store->metric_volume_success = (int) ($row?->volume_success ?? 0);
            })
            ->sortByDesc('metric_trx_total')
            ->values();
    }

    private function summary(array $filters): array
    {
        $totals = (clone $this->transactions($filters))
            ->reorder()
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'success' THEN 1 ELSE 0 END) as trx_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'success' THEN topup_requests.amount ELSE 0 END), 0) as volume_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'success' THEN topup_requests.net_amount ELSE 0 END), 0) as total_settlement")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'pending' THEN topup_requests.amount ELSE 0 END), 0) as pending_transaction_amount")
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'pending' THEN 1 ELSE 0 END) as trx_pending")
            ->selectRaw("SUM(CASE WHEN topup_requests.status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as trx_expired")
            ->first();
        $fee = $this->feeTotalsForFilters($filters);
        $hgSettlement = $this->hgSettlements($filters)->sum('net_amount');

        return [
            'volume_success' => (int) ($totals->volume_success ?? 0),
            'pending_transaction_amount' => (int) ($totals->pending_transaction_amount ?? 0),
            'total_settlement' => (int) ($totals->total_settlement ?? 0),
            'hg_settlement' => (int) $hgSettlement,
            'trx_total' => (int) ($totals->trx_total ?? 0),
            'trx_pending' => (int) ($totals->trx_pending ?? 0),
            'trx_expired' => (int) ($totals->trx_expired ?? 0),
            'issue_total' => $this->ticketQuery($filters)->count(),
            'issue_solved' => $this->ticketQuery($filters)->where('status', 'done')->count(),
            'agent_total' => $this->agents($filters)->count(),
            'merchant_total' => $this->merchants($filters)->count(),
            'unassigned' => $this->merchants($filters)->whereNull('agent_id')->count(),
            'fee_ma' => $fee['ma'],
            'fee_agent' => $fee['agent'],
            'fee_merchant' => $fee['merchant'],
        ];
    }

    private function overviewDetails(array $filters): array
    {
        $latestTransactions = $this->transactions($filters)->limit(200)->get();
        $successTransactions = $this->transactions(array_merge($filters, ['status' => 'success']))->limit(200)->get();
        $pendingTransactions = $this->transactions(array_merge($filters, ['status' => 'pending']))->limit(200)->get();
        $expiredTransactions = $this->transactions(array_merge($filters, ['status' => 'all']))->whereIn('topup_requests.status', ['expired', 'failed', 'rejected'])->limit(200)->get();
        $hgSettlements = $this->hgSettlements($filters)->with('merchant.agent')->latest('settlement_date')->limit(200)->get();
        $merchants = $this->merchants($filters)->limit(200)->get();
        $agents = $this->agents($filters)->withCount('merchants')->get();
        $tickets = $this->ticketQuery($filters)->with(['merchant.agent', 'topupRequest'])
            ->latest()
            ->limit(200)
            ->get();
        return [
            'volume_success' => ['title' => 'Volume Sukses', 'type' => 'transaction', 'items' => $this->transactionItems($successTransactions)],
            'pending_transaction_amount' => ['title' => 'Pending Transaksi', 'type' => 'transaction', 'items' => $this->transactionItems($pendingTransactions)],
            'total_settlement' => ['title' => 'Total Settlement', 'type' => 'transaction', 'items' => $this->transactionItems($successTransactions)],
            'hg_settlement' => ['title' => 'Settlement Real HG', 'type' => 'settlement', 'items' => $this->settlementItems($hgSettlements)],
            'trx_total' => ['title' => 'Total Transaksi', 'type' => 'transaction', 'items' => $this->transactionItems($latestTransactions)],
            'trx_pending' => ['title' => 'Transaksi Pending', 'type' => 'transaction', 'items' => $this->transactionItems($pendingTransactions)],
            'trx_expired' => ['title' => 'Transaksi Expired', 'type' => 'transaction', 'items' => $this->transactionItems($expiredTransactions)],
            'issue_total' => ['title' => 'Total Issue', 'type' => 'ticket', 'items' => $this->ticketItems($tickets)],
            'issue_solved' => ['title' => 'Issue Solved', 'type' => 'ticket', 'items' => $this->ticketItems($tickets->where('status', 'done'))],
            'agent_total' => ['title' => 'List Agen', 'type' => 'agent', 'items' => $this->agentItems($agents)],
            'merchant_total' => ['title' => 'List Toko', 'type' => 'merchant', 'items' => $this->merchantItems($merchants)],
            'unassigned' => ['title' => 'Toko Belum Assign Agen', 'type' => 'merchant', 'items' => $this->merchantItems($merchants->whereNull('agent_id'))],
            'fee_ma' => ['title' => 'Detail Fee MA', 'type' => 'fee', 'items' => $this->feeItems($successTransactions, 'ma_fee_percent')],
            'fee_agent' => ['title' => 'Detail Fee Agen', 'type' => 'fee', 'items' => $this->feeItems($successTransactions, 'agent_fee_percent')],
        ];
    }

    private function reportAgents(array $filters)
    {
        $metrics = TopupRequest::query()
            ->join('merchants', 'merchants.id', '=', 'topup_requests.merchant_id')
            ->join('agents', 'agents.id', '=', 'merchants.agent_id')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->where('agents.ma_user_id', $maId))
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('merchants.agent_id, COALESCE(SUM(topup_requests.amount), 0) as volume')
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'success' THEN 1 ELSE 0 END) as settled")
            ->whereNotNull('merchants.agent_id')
            ->groupBy('merchants.agent_id')
            ->get()
            ->keyBy('agent_id');

        return $this->agents($this->blankFilters())
            ->withCount('merchants')
            ->get()
            ->map(function (Agent $agent) use ($metrics) {
                $row = $metrics->get($agent->id);

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'stores' => (int) $agent->merchants_count,
                    'volume' => (int) ($row?->volume ?? 0),
                    'pending' => (int) ($row?->pending ?? 0),
                    'settled' => (int) ($row?->settled ?? 0),
                ];
            })
            ->sortByDesc('volume')
            ->when($filters['agents_view'] !== 'all', fn ($items) => $items->take(5))
            ->values();
    }

    private function selectedStoreStats(array $filters): array
    {
        $row = (clone $this->transactions($filters))
            ->reorder()
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'success' THEN 1 ELSE 0 END) as trx_total")
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'success' THEN 1 ELSE 0 END) as trx_success")
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'pending' THEN 1 ELSE 0 END) as trx_pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'success' THEN topup_requests.amount ELSE 0 END), 0) as amount_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'success' THEN topup_requests.net_amount ELSE 0 END), 0) as settlement")
            ->first();

        return [
            'trx_total' => (int) ($row->trx_total ?? 0),
            'trx_success' => (int) ($row->trx_success ?? 0),
            'trx_pending' => (int) ($row->trx_pending ?? 0),
            'amount_success' => (int) ($row->amount_success ?? 0),
            'settlement' => (int) ($row->settlement ?? 0),
        ];
    }

    private function feeTotals($transactions): array
    {
        return $transactions->where('status', 'success')->reduce(function (array $carry, TopupRequest $trx) {
            $amount = (int) $trx->amount;
            $carry['ma'] += (int) round($amount * ((float) $trx->merchant?->ma_fee_percent / 100));
            $carry['agent'] += (int) round($amount * ((float) $trx->merchant?->agent_fee_percent / 100));
            $carry['merchant'] += (int) round($amount * ((float) $trx->merchant?->merchant_mdr_percent / 100));

            return $carry;
        }, ['ma' => 0, 'agent' => 0, 'merchant' => 0]);
    }

    private function feeTotalsForFilters(array $filters): array
    {
        $row = (clone $this->transactions(array_merge($filters, ['status' => 'success'])))
            ->reorder()
            ->join('merchants as fee_merchants', 'fee_merchants.id', '=', 'topup_requests.merchant_id')
            ->selectRaw('COALESCE(SUM(topup_requests.amount * fee_merchants.ma_fee_percent / 100), 0) as ma')
            ->selectRaw('COALESCE(SUM(topup_requests.amount * fee_merchants.agent_fee_percent / 100), 0) as agent')
            ->selectRaw('COALESCE(SUM(topup_requests.amount * fee_merchants.merchant_mdr_percent / 100), 0) as merchant')
            ->first();

        return [
            'ma' => (int) round((float) ($row->ma ?? 0)),
            'agent' => (int) round((float) ($row->agent ?? 0)),
            'merchant' => (int) round((float) ($row->merchant ?? 0)),
        ];
    }

    private function transactionItems($transactions): array
    {
        return $transactions->take(200)->map(fn (TopupRequest $trx) => [
            'date' => $trx->submitted_at?->format('d/m/y H.i') ?: '-',
            'title' => $trx->customer_reference ?: $trx->gateway_ref_id ?: $trx->payment_id ?: '-',
            'subtitle' => ($trx->merchant?->name ?: '-').' / '.($trx->merchant?->agent?->name ?: '-'),
            'status' => $trx->status,
            'amount' => (int) $trx->amount,
            'meta' => $trx->rrn ?: $trx->payment_id ?: '-',
        ])->values()->all();
    }

    private function settlementItems($settlements): array
    {
        return $settlements->take(200)->map(fn (MerchantSettlement $settlement) => [
            'date' => $settlement->settlement_date?->format('d/m/y') ?: '-',
            'title' => $settlement->reference,
            'subtitle' => ($settlement->merchant?->name ?: '-').' / '.($settlement->merchant?->agent?->name ?: '-'),
            'status' => $settlement->status,
            'amount' => (int) $settlement->net_amount,
            'meta' => $settlement->batch_name ?: '-',
        ])->values()->all();
    }

    private function hgSettlements(array $filters)
    {
        return MerchantSettlement::query()
            ->where('gateway', 'hilogate')
            ->whereIn('status', ['APPROVED', 'SUCCESS', 'DONE', 'SETTLED'])
            ->when($filters['from'], fn ($query) => $query->whereDate('settlement_date', '>=', $this->rangeStart($filters['from'])->toDateString()))
            ->when($filters['to'], fn ($query) => $query->whereDate('settlement_date', '<=', $this->rangeEnd($filters['to'])->toDateString()))
            ->whereHas('merchant', function ($query) use ($filters): void {
                $query
                    ->when($this->currentMaId(), fn ($nested, $maId) => $nested->whereRelation('agent', 'ma_user_id', $maId))
                    ->when($filters['q'] !== '', fn ($nested) => $nested->where(fn ($search) => $search->where('name', 'like', $filters['q'].'%')->orWhere('slug', 'like', $filters['q'].'%')->orWhere('merchant_id', 'like', $filters['q'].'%')->orWhereRelation('agent', 'name', 'like', $filters['q'].'%')))
                    ->when($filters['agent_id'] !== 'all', fn ($nested) => $nested->where('agent_id', $filters['agent_id']))
                    ->when($filters['store_id'] !== 'all', fn ($nested) => $nested->whereKey($filters['store_id']))
                    ->when($filters['type'] !== 'all', fn ($nested) => $nested->where('merchant_type', $filters['type']));
            });
    }

    private function merchantItems($merchants): array
    {
        return $merchants->take(200)->map(fn (Merchant $merchant) => [
            'title' => $merchant->name,
            'subtitle' => $merchant->agent?->name ?: 'Belum assign agen',
            'status' => $merchant->approval_status,
            'amount' => null,
            'meta' => $merchant->merchant_id ?: $merchant->slug,
        ])->values()->all();
    }

    private function agentItems($agents): array
    {
        return $agents->take(200)->map(fn (Agent $agent) => [
            'title' => $agent->name,
            'subtitle' => $agent->email ?: '-',
            'status' => $agent->is_active ? 'Active' : 'Suspended',
            'amount' => null,
            'meta' => ($agent->merchants_count ?? 0).' toko',
        ])->values()->all();
    }

    private function ticketItems($tickets): array
    {
        return $tickets->take(200)->map(fn (SupportTicket $ticket) => [
            'date' => $ticket->created_at?->timezone('Asia/Jakarta')->format('d/m/y H.i') ?: '-',
            'title' => $ticket->ticket_no ?: $ticket->reference,
            'subtitle' => ($ticket->merchant?->name ?: '-').' / '.$ticket->issue,
            'status' => $ticket->status,
            'amount' => (int) ($ticket->topupRequest?->amount ?? 0),
            'meta' => $ticket->center_status ?: '-',
        ])->values()->all();
    }

    private function feeItems($transactions, string $percentColumn): array
    {
        return $transactions->take(200)->map(function (TopupRequest $trx) use ($percentColumn) {
            $percent = (float) $trx->merchant?->{$percentColumn};

            return [
                'date' => $trx->submitted_at?->format('d/m/y H.i') ?: '-',
                'title' => $trx->customer_reference ?: $trx->gateway_ref_id ?: $trx->payment_id ?: '-',
                'subtitle' => ($trx->merchant?->name ?: '-').' / '.$percent.'%',
                'status' => $trx->status,
                'amount' => (int) round((int) $trx->amount * ($percent / 100)),
                'meta' => 'Volume '.$trx->amount,
            ];
        })->values()->all();
    }

    private function storeRanking(array $filters)
    {
        return TopupRequest::query()
            ->with('merchant')
            ->where('status', 'success')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->whereRelation('merchant.agent', 'ma_user_id', $maId))
            ->when($filters['from'], fn ($query) => $query->where('submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('merchant_id, COUNT(*) as trx')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('merchant_id')
            ->orderBy('trx')
            ->orderBy('volume')
            ->limit(10)
            ->get();
    }

    private function agentRanking(array $filters)
    {
        return TopupRequest::query()
            ->join('merchants', 'merchants.id', '=', 'topup_requests.merchant_id')
            ->with('merchant.agent')
            ->where('topup_requests.status', 'success')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->whereRelation('merchant.agent', 'ma_user_id', $maId))
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->whereNotNull('merchants.agent_id')
            ->selectRaw('merchants.agent_id, COUNT(*) as trx')
            ->selectRaw('COALESCE(SUM(topup_requests.amount), 0) as volume')
            ->groupBy('merchants.agent_id')
            ->orderBy('trx')
            ->orderBy('volume')
            ->limit(10)
            ->get()
            ->each(function ($row): void {
                $row->agent = Agent::query()->find($row->agent_id);
            });
    }

    private function storeSummaries(array $filters)
    {
        $balances = MerchantGatewayBalance::query()->get()->keyBy('merchant_id');
        $merchants = $this->merchants($filters)->get();
        $totals = TopupRequest::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->where('status', 'success')
            ->when($filters['from'], fn ($query) => $query->where('submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('merchant_id, COUNT(*) as trx_total')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume_success')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as settlement')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        return $merchants
            ->map(function (Merchant $merchant) use ($balances, $totals) {
                $row = $totals->get($merchant->id);

                return [
                    'name' => $merchant->name,
                    'agent' => $merchant->agent?->name ?: '-',
                    'trx_total' => (int) ($row?->trx_total ?? 0),
                    'volume_success' => (int) ($row?->volume_success ?? 0),
                    'pending_balance' => (int) ($balances->get($merchant->id)?->pending_balance ?? 0),
                    'settlement' => (int) ($row?->settlement ?? 0),
                ];
            })
            ->sortBy('trx_total')
            ->take(10)
            ->values();
    }

    private function ticketQuery(array $filters)
    {
        return SupportTicket::query()
            ->when($this->currentMaId(), fn ($query, $maId) => $query->whereRelation('merchant.agent', 'ma_user_id', $maId))
            ->when($filters['from'], fn ($query) => $query->where('created_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('created_at', '<=', $this->rangeEnd($filters['to'])));
    }

    private function rangeStart(string $value): CarbonImmutable
    {
        $parsed = CarbonImmutable::parse($value, 'Asia/Jakarta');

        return str_contains($value, ':') ? $parsed : $parsed->startOfDay();
    }

    private function rangeEnd(string $value): CarbonImmutable
    {
        $parsed = CarbonImmutable::parse($value, 'Asia/Jakarta');

        return str_contains($value, ':') ? $parsed : $parsed->endOfDay();
    }

    private function metricPeriod($query, array $filters)
    {
        return $query
            ->when($filters['from'], fn ($nested) => $nested->whereDate('metric_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($nested) => $nested->whereDate('metric_date', '<=', $filters['to']));
    }

    private function currentMaId(): ?int
    {
        $user = request()->user();

        return $user?->role === 'ma' ? (int) $user->id : null;
    }

    private function canUseAgent(Agent $agent): bool
    {
        $maId = $this->currentMaId();

        return $maId === null || (int) $agent->ma_user_id === $maId;
    }

    private function canUseMerchant(Merchant $merchant): bool
    {
        $maId = $this->currentMaId();
        if ($maId === null) {
            return true;
        }

        $merchant->loadMissing('agent');

        return (int) $merchant->agent?->ma_user_id === $maId;
    }

    private function normalizePercentInputs(Request $request, array $fields): void
    {
        $request->merge(collect($fields)->mapWithKeys(fn ($field) => $request->has($field) ? [$field => str_replace(',', '.', (string) $request->input($field))] : [])->all());
    }

    private function uniqueAgentCode(string $name): string
    {
        $base = 'AGN-'.Str::upper(Str::slug($name, '-'));
        $code = Str::limit($base, 32, '');
        $i = 2;
        while (Agent::query()->where('code', $code)->exists()) {
            $code = Str::limit($base, 28, '').'-'.$i++;
        }
        return $code;
    }

    private function uniqueMerchantSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;
        while (Merchant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }
        return $slug;
    }
}
