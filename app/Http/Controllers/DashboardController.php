<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Models\MerchantDailyMetric;
use App\Models\MerchantRegistration;
use App\Models\MerchantSettlement;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Services\AuditLogService;
use App\Services\MetricsService;
use App\Services\Navigation\MenuBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function ma(MenuBuilder $menus, MetricsService $metrics): View
    {
        return view('paygrid.ma-overview', [
            'roleLabel' => 'MA',
            'menus' => $menus->ma(),
            'active' => 'overview',
            'merchants' => $metrics->maMerchants(),
        ]);
    }

    public function maSimple(string $page, MenuBuilder $menus): View
    {
        $metrics = app(MetricsService::class);

        return view('paygrid.simple-page', [
            'roleLabel' => 'MA',
            'menus' => $menus->ma(),
            'active' => $page,
            'title' => match ($page) {
                'approval' => 'Merchant Approval',
                'stores' => 'List Toko',
                'mapping' => 'Mapping Agen',
                'agents' => 'Agen',
                'fee' => 'Fee Management',
                'create-store' => 'Create Toko',
                default => 'Report',
            },
            'subtitle' => 'Halaman Laravel unified. Data final dibaca dari DB dan service PayGrid.',
            'merchants' => $metrics->maMerchants(),
            'agents' => Agent::query()->orderBy('name')->get(),
            'registrations' => MerchantRegistration::query()->with(['agent', 'merchant'])->latest()->get(),
            'tickets' => SupportTicket::query()->with('merchant')->latest()->limit(20)->get(),
        ]);
    }

    public function agent(MenuBuilder $menus, MetricsService $metrics): View
    {
        $agent = $this->currentAgent();

        return view('paygrid.agent-overview', [
            'roleLabel' => $agent->name,
            'menus' => $menus->agent(),
            'active' => 'overview',
            'agent' => $agent,
            'merchants' => $metrics->agentMerchants($agent),
        ]);
    }

    public function agentSimple(string $page, MenuBuilder $menus): View
    {
        $agent = $this->currentAgent();
        $metrics = app(MetricsService::class);
        $requestFilters = [
            'q' => trim((string) request('q', '')),
            'status' => (string) request('status', 'all'),
            'from' => (string) request('from', ''),
            'to' => (string) request('to', ''),
        ];
        $registrations = MerchantRegistration::query()
            ->where('agent_id', $agent->id)
            ->with(['agent', 'merchant'])
            ->when($requestFilters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('store_name', 'like', $requestFilters['q'].'%')
                ->orWhere('token', 'like', $requestFilters['q'].'%')
                ->orWhereRelation('merchant', 'merchant_id', 'like', $requestFilters['q'].'%')))
            ->when($requestFilters['status'] !== 'all', fn ($query) => $query->where('status', $requestFilters['status']))
            ->when($requestFilters['from'] !== '', fn ($query) => $query->whereDate('created_at', '>=', $requestFilters['from']))
            ->when($requestFilters['to'] !== '', fn ($query) => $query->whereDate('created_at', '<=', $requestFilters['to']))
            ->latest()
            ->get();

        return view('paygrid.simple-page', [
            'roleLabel' => $agent->name,
            'menus' => $menus->agent(),
            'active' => $page,
            'title' => match ($page) {
                'create-store', 'new-store' => 'Create Toko',
                'status-request' => 'Status Request Toko',
                default => 'Agen',
            },
            'subtitle' => 'Request dari form masuk ke agen dahulu, lalu agen submit ke MA.',
            'agent' => $agent,
            'merchants' => $metrics->agentMerchants($agent),
            'requestFilters' => $requestFilters,
            'registrations' => $registrations,
            'onboardingLinks' => AgentOnboardingLink::query()->where('agent_id', $agent->id)->with('registration')->latest()->limit(10)->get(),
        ]);
    }

    public function storeAgentOnboardingLink(Request $request, AuditLogService $audit): RedirectResponse
    {
        $agent = $this->currentAgent();
        $data = $request->validate([
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_telegram' => ['nullable', 'string', 'max:80'],
        ]);

        $link = AgentOnboardingLink::query()->create([
            'agent_id' => $agent->id,
            'token' => Str::random(48),
            'recipient_email' => $data['recipient_email'] ?? null,
            'recipient_telegram' => $data['recipient_telegram'] ?? null,
            'status' => 'active',
            'expires_at' => now()->addHours(max(1, (int) config('paygrid.onboarding.link_expires_hours', 24))),
        ]);
        $audit->record('agent.onboarding_link_created', $link, null, $link->only(['agent_id', 'recipient_email', 'recipient_telegram', 'status', 'expires_at']));

        return back()->with('status', 'Link form unik berhasil dibuat.')->with('onboarding_link', route('merchant-registration.token-form', $link));
    }

    public function expireAgentOnboardingLink(AgentOnboardingLink $link, AuditLogService $audit): RedirectResponse
    {
        $agent = $this->currentAgent();
        abort_unless((int) $link->agent_id === (int) $agent->id, 403);
        abort_unless($link->status === 'active', 422);

        $before = $link->only(['status', 'expires_at']);
        $link->update(['status' => 'expired', 'expires_at' => now()]);
        $audit->record('agent.onboarding_link_expired', $link, $before, $link->only(array_keys($before)));

        return back()->with('status', 'Link onboarding berhasil di-expire.');
    }

    public function deleteAgentRegistration(MerchantRegistration $registration, AuditLogService $audit): RedirectResponse
    {
        $agent = $this->currentAgent();
        abort_unless((int) $registration->agent_id === (int) $agent->id, 403);
        abort_unless(in_array($registration->status, ['draft', 'pending_agent'], true), 422);

        $before = $registration->only(['agent_id', 'store_name', 'status', 'merchant_id']);
        $links = AgentOnboardingLink::query()
            ->where('merchant_registration_id', $registration->id)
            ->get();
        foreach ($links as $link) {
            $linkBefore = $link->only(['merchant_registration_id', 'status', 'expires_at']);
            $link->update(['merchant_registration_id' => null, 'status' => 'expired', 'expires_at' => now()]);
            $audit->record('agent.onboarding_link_expired_by_request_delete', $link, $linkBefore, $link->only(array_keys($linkBefore)));
        }
        $audit->record('agent.merchant_registration_deleted', $registration, $before, null);
        $registration->delete();

        return back()->with('status', 'Request toko berhasil dihapus.');
    }

    public function bulkAgentOnboardingLinks(Request $request, AuditLogService $audit): RedirectResponse
    {
        $agent = $this->currentAgent();
        $data = $request->validate([
            'action' => ['required', 'in:expire'],
            'link_ids' => ['required', 'array'],
            'link_ids.*' => ['integer'],
        ]);
        $links = AgentOnboardingLink::query()
            ->where('agent_id', $agent->id)
            ->whereIn('id', $data['link_ids'])
            ->where('status', 'active')
            ->get();

        foreach ($links as $link) {
            $before = $link->only(['status', 'expires_at']);
            $link->update(['status' => 'expired', 'expires_at' => now()]);
            $audit->record('agent.onboarding_link_bulk_expired', $link, $before, $link->only(array_keys($before)));
        }

        return back()->with('status', $links->count().' link berhasil di-expire.');
    }

    public function bulkAgentRegistrations(Request $request, AuditLogService $audit): RedirectResponse
    {
        $agent = $this->currentAgent();
        $data = $request->validate([
            'action' => ['required', 'in:submit,delete'],
            'registration_ids' => ['required', 'array'],
            'registration_ids.*' => ['integer'],
        ]);
        $registrations = MerchantRegistration::query()
            ->where('agent_id', $agent->id)
            ->whereIn('id', $data['registration_ids'])
            ->whereIn('status', ['draft', 'pending_agent'])
            ->get();

        if ($data['action'] === 'submit') {
            foreach ($registrations as $registration) {
                $before = $registration->only(['status', 'submitted_to_ma_at']);
                $registration->update(['status' => 'pending_ma', 'submitted_to_ma_at' => now()]);
                $audit->record('merchant_registration.bulk_submitted_to_ma', $registration, $before, $registration->only(array_keys($before)));
                $this->notifyMaForRegistration($registration->fresh(['agent']));
            }

            return back()->with('status', $registrations->count().' request berhasil dikirim ke MA.');
        }

        foreach ($registrations as $registration) {
            $before = $registration->only(['agent_id', 'store_name', 'status', 'merchant_id']);
            AgentOnboardingLink::query()->where('merchant_registration_id', $registration->id)->update(['merchant_registration_id' => null, 'status' => 'expired', 'expires_at' => now()]);
            $audit->record('agent.merchant_registration_bulk_deleted', $registration, $before, null);
            $registration->delete();
        }

        return back()->with('status', $registrations->count().' request berhasil dihapus.');
    }

    public function exportAgentReport()
    {
        $agent = $this->currentAgent();
        $merchants = app(MetricsService::class)->agentMerchants($agent);

        return response()->streamDownload(function () use ($merchants): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['date', 'merchant_name', 'merchant_id', 'type', 'status', 'volume_success', 'trx_total', 'pending_balance']);
            foreach ($merchants as $merchant) {
                fputcsv($handle, [
                    now('Asia/Jakarta')->toDateString(),
                    $merchant->name,
                    $merchant->merchant_id,
                    $merchant->merchant_type,
                    $merchant->approval_status,
                    (int) ($merchant->metric_volume_success ?? 0),
                    (int) ($merchant->metric_trx_total ?? 0),
                    0,
                ]);
            }
            fclose($handle);
        }, 'agent-report-'.now('Asia/Jakarta')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function notifyMaForRegistration(MerchantRegistration $registration): void
    {
        $ma = $registration->agent?->ma;
        if ($ma) {
            $ma->notify(new \App\Notifications\MerchantRegistrationSubmittedToMa($registration));
        }
    }

    private function currentAgent(): Agent
    {
        $user = request()->user();
        if ($user?->role === 'agent') {
            return Agent::query()
                ->where('code', $user->username)
                ->orWhere('email', $user->email)
                ->firstOrFail();
        }

        return Agent::query()->where('code', 'AG-EPC')->firstOrFail();
    }

    public function adminSimple(string $page, MenuBuilder $menus): View
    {
        return view('paygrid.simple-page', [
            'roleLabel' => 'Admin',
            'menus' => $menus->admin(),
            'active' => $page,
            'title' => match ($page) {
                'logs' => 'Log Aktivitas',
                default => 'User Dashboard',
            },
            'subtitle' => 'Area admin toko untuk user, log, topup request, checklist, finance, dan CS.',
            'merchants' => app(MetricsService::class)->maMerchants(),
            'registrations' => MerchantRegistration::query()->with(['agent', 'merchant'])->latest()->get(),
        ]);
    }

    public function merchantCs(Merchant $merchant, string $page, MenuBuilder $menus, MetricsService $metrics): View
    {
        $menu = $menus->merchantCs($merchant);

        abort_if(! collect($menu)->contains('key', $page), 404);

        $period = request('period', $page === 'tickets' ? 'this_month' : 'today');
        [$defaultFrom, $defaultTo] = match ($period) {
            'last_month' => [now('Asia/Jakarta')->subMonthNoOverflow()->startOfMonth()->toDateString(), now('Asia/Jakarta')->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'all' => [null, null],
            'this_month' => [now('Asia/Jakarta')->startOfMonth()->toDateString(), now('Asia/Jakarta')->toDateString()],
            default => [now('Asia/Jakarta')->toDateString(), now('Asia/Jakarta')->toDateString()],
        };
        $from = request('from', $defaultFrom);
        $to = request('to', $defaultTo ?: $from);
        if ($period === 'all') {
            $from = null;
            $to = null;
        }
        $rangeStart = $from ? CarbonImmutable::parse($from, 'Asia/Jakarta')->startOfDay() : null;
        $rangeEnd = $to ? CarbonImmutable::parse($to, 'Asia/Jakarta')->endOfDay() : null;
        $search = trim((string) request('q', ''));
        $rangeQuery = $this->transactionBaseQuery($merchant, $rangeStart, $rangeEnd, $search);
        $requests = TopupRequest::query()
            ->with('ticket')
            ->where('merchant_id', $merchant->id)
            ->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('submitted_at', [$rangeStart, $rangeEnd]))
            ->when($page === 'checklist', fn ($query) => $query->where('status', 'success'))
            ->when(request('status') && request('status') !== 'all', fn ($query) => request('status') === 'expired' ? $query->whereIn('status', ['expired', 'failed', 'rejected']) : $query->where('status', request('status')))
            ->when($search !== '', fn ($query) => $this->applyTransactionSearch($query, $search))
            ->orderByRaw("CASE WHEN status = 'success' AND is_processed = 0 THEN 0 WHEN status = 'success' AND is_processed = 1 THEN 1 WHEN status = 'pending' THEN 2 WHEN status = 'expired' THEN 3 ELSE 4 END")
            ->latest('submitted_at')
            ->simplePaginate(config('paygrid.reports.default_page_size', 50))
            ->withQueryString();
        $tickets = SupportTicket::query()
            ->where('merchant_id', $merchant->id)
            ->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('created_at', [$rangeStart, $rangeEnd]))
            ->with('topupRequest')
            ->when(request('status') && request('status') !== 'all', fn ($query) => $query->where('status', request('status')))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('ticket_no', 'like', "{$search}%")
                    ->orWhere('reference', 'like', "{$search}%")
                    ->orWhere('client_reference', 'like', "{$search}%");
            }))
            ->latest()
            ->paginate(config('paygrid.reports.default_page_size', 50), ['*'], 'tickets_page')
            ->withQueryString();

        return view('paygrid.merchant-cs', [
            'roleLabel' => $merchant->name,
            'merchant' => $merchant,
            'menus' => $menu,
            'active' => $page,
            'summary' => $metrics->merchantSummary($merchant, $from, $to),
            'stats' => $stats = $this->dashboardStats($merchant, $rangeStart, $rangeEnd, $search),
            'topupCards' => $this->topupCards($stats),
            'gatewayBalance' => in_array($page, ['topup', 'checklist', 'history'], true) ? app(\App\Services\GatewayBalanceService::class)->current($merchant) : ['active' => 0, 'pending' => 0, 'source' => 'none'],
            'latestSync' => GatewaySyncLog::query()
                ->where('merchant_id', $merchant->id)
                ->where('direction', 'pull')
                ->latest('finished_at')
                ->first(),
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'requests' => $requests,
            'tickets' => $tickets,
            'ticketStats' => [
                'total' => (int) SupportTicket::query()->where('merchant_id', $merchant->id)->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('created_at', [$rangeStart, $rangeEnd]))->count(),
                'open' => (int) SupportTicket::query()->where('merchant_id', $merchant->id)->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('created_at', [$rangeStart, $rangeEnd]))->whereIn('status', ['not_started', 'open', 'in_progress'])->count(),
                'done' => (int) SupportTicket::query()->where('merchant_id', $merchant->id)->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('created_at', [$rangeStart, $rangeEnd]))->where('status', 'done')->count(),
            ],
        ]);
    }

    private function transactionBaseQuery(Merchant $merchant, ?CarbonImmutable $rangeStart, ?CarbonImmutable $rangeEnd, string $search): \Illuminate\Database\Eloquent\Builder
    {
        return TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('submitted_at', [$rangeStart, $rangeEnd]))
            ->when($search !== '', fn ($query) => $this->applyTransactionSearch($query, $search));
    }

    private function applyTransactionSearch($query, string $search): void
    {
        $query->where(function ($nested) use ($search) {
            $nested->where('payment_id', 'like', "{$search}%")
                ->orWhere('gateway_ref_id', 'like', "{$search}%")
                ->orWhere('rrn', 'like', "{$search}%")
                ->orWhere('transaction_id', 'like', "{$search}%")
                ->orWhere('customer_reference', 'like', "{$search}%");
        });
    }

    private function dashboardStats(Merchant $merchant, ?CarbonImmutable $rangeStart, ?CarbonImmutable $rangeEnd, string $search): array
    {
        if ($search === '') {
            $metrics = MerchantDailyMetric::query()
                ->where('merchant_id', $merchant->id)
                ->when($rangeStart && $rangeEnd, fn ($query) => $query
                    ->whereDate('metric_date', '>=', $rangeStart->toDateString())
                    ->whereDate('metric_date', '<=', $rangeEnd->toDateString()))
                ->selectRaw('COALESCE(SUM(trx_total), 0) as total')
                ->selectRaw('COALESCE(SUM(trx_success), 0) as success')
                ->selectRaw('COALESCE(SUM(trx_pending), 0) as pending')
                ->selectRaw('COALESCE(SUM(trx_expired), 0) as expired')
                ->selectRaw('COALESCE(SUM(amount_success), 0) as volume_success')
                ->selectRaw('COALESCE(SUM(amount_total), 0) as volume_total')
                ->selectRaw('COALESCE(SUM(amount_pending), 0) as pending_amount')
                ->selectRaw('COALESCE(SUM(amount_expired), 0) as expired_amount')
                ->selectRaw('COALESCE(SUM(trx_success_processed), 0) as success_checked_count')
                ->selectRaw('COALESCE(SUM(amount_success_processed), 0) as success_checked_amount')
                ->selectRaw('COALESCE(SUM(trx_success_unprocessed), 0) as success_unchecked_count')
                ->selectRaw('COALESCE(SUM(amount_success_unprocessed), 0) as success_unchecked_amount')
                ->first();

            return [
                'total' => (int) $metrics->total,
                'success' => (int) $metrics->success,
                'pending' => (int) $metrics->pending,
                'expired' => (int) $metrics->expired,
                'failed' => 0,
                'volume_success' => (int) $metrics->volume_success,
                'volume_total' => (int) $metrics->volume_total,
                'pending_amount' => (int) $metrics->pending_amount,
                'expired_amount' => (int) $metrics->expired_amount,
                'success_checked_count' => (int) $metrics->success_checked_count,
                'success_checked_amount' => (int) $metrics->success_checked_amount,
                'success_unchecked_count' => (int) $metrics->success_unchecked_count,
                'success_unchecked_amount' => (int) $metrics->success_unchecked_amount,
            ];
        }

        $row = $this->transactionBaseQuery($merchant, $rangeStart, $rangeEnd, $search)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as expired")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume_success")
            ->selectRaw('COALESCE(SUM(amount), 0) as volume_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN amount ELSE 0 END), 0) as expired_amount")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND is_processed = 1 THEN 1 ELSE 0 END) as success_checked_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' AND is_processed = 1 THEN amount ELSE 0 END), 0) as success_checked_amount")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND is_processed = 0 THEN 1 ELSE 0 END) as success_unchecked_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' AND is_processed = 0 THEN amount ELSE 0 END), 0) as success_unchecked_amount")
            ->first();

        return [
            'total' => (int) $row->total,
            'success' => (int) $row->success,
            'pending' => (int) $row->pending,
            'expired' => (int) $row->expired,
            'failed' => 0,
            'volume_success' => (int) $row->volume_success,
            'volume_total' => (int) $row->volume_total,
            'pending_amount' => (int) $row->pending_amount,
            'expired_amount' => (int) $row->expired_amount,
            'success_checked_count' => (int) $row->success_checked_count,
            'success_checked_amount' => (int) $row->success_checked_amount,
            'success_unchecked_count' => (int) $row->success_unchecked_count,
            'success_unchecked_amount' => (int) $row->success_unchecked_amount,
        ];
    }

    private function topupCards(array $stats): array
    {
        return [
            'pending_count' => $stats['pending'],
            'pending_amount' => $stats['pending_amount'],
            'success_unchecked_count' => $stats['success_unchecked_count'],
            'success_unchecked_amount' => $stats['success_unchecked_amount'],
            'success_checked_count' => $stats['success_checked_count'],
            'success_checked_amount' => $stats['success_checked_amount'],
            'expired_count' => $stats['expired'],
            'expired_amount' => $stats['expired_amount'],
        ];
    }

    public function merchantFinance(Merchant $merchant, string $page, MenuBuilder $menus): View
    {
        $menu = $menus->merchantFinance($merchant);

        abort_if(! collect($menu)->contains('key', $page), 404);

        $period = request('period', $page === 'report' ? 'all' : 'this_month');
        [$defaultFrom, $defaultTo] = match ($period) {
            'last_month' => [now('Asia/Jakarta')->subMonthNoOverflow()->startOfMonth()->toDateString(), now('Asia/Jakarta')->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'all' => [null, null],
            default => [now('Asia/Jakarta')->startOfMonth()->toDateString(), now('Asia/Jakarta')->toDateString()],
        };
        $from = request('from', $defaultFrom);
        $to = request('to', $defaultTo ?: $from);
        if ($period === 'all') {
            $from = null;
            $to = null;
        }
        $rangeStart = $from ? CarbonImmutable::parse($from, 'Asia/Jakarta')->startOfDay() : null;
        $rangeEnd = $to ? CarbonImmutable::parse($to, 'Asia/Jakarta')->endOfDay() : null;
        $search = trim((string) request('q', ''));
        $status = request('status', 'all');
        $settlementStatus = request('settlement_status', 'all');

        $transactions = TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('submitted_at', [$rangeStart, $rangeEnd]))
            ->when($status !== 'all', fn ($query) => $status === 'expired' ? $query->whereIn('status', ['expired', 'failed', 'rejected']) : $query->where('status', $status))
            ->when($search !== '', fn ($query) => $this->applyTransactionSearch($query, $search))
            ->latest('submitted_at')
            ->simplePaginate(config('paygrid.reports.default_page_size', 50))
            ->withQueryString();

        $settlementRows = MerchantSettlement::query()
            ->where('merchant_id', $merchant->id)
            ->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('settlement_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]))
            ->when($settlementStatus !== 'all', fn ($query) => $query->where('status', $settlementStatus))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('reference', 'like', "{$search}%")
                    ->orWhere('batch_name', 'like', "{$search}%")
                    ->orWhere('status', 'like', "{$search}%");
            }))
            ->latest('settlement_date')
            ->latest('processed_at')
            ->simplePaginate(config('paygrid.reports.default_page_size', 50), ['*'], 'settlement_page')
            ->withQueryString();

        $stats = $this->financeStats($merchant, $rangeStart, $rangeEnd, $search);

        return view('paygrid.merchant-finance', [
            'roleLabel' => $merchant->name.' Finance',
            'merchant' => $merchant,
            'menus' => $menu,
            'active' => $page,
            'title' => match ($page) {
                'settlement' => 'Settlement',
                'report' => 'Report Finance',
                default => 'Finance Overview',
            },
            'subtitle' => $merchant->isScript()
                ? 'Finance script membaca transaksi gateway dari DB lokal, saldo cache, dan estimasi settlement per toko.'
                : 'Finance CM membaca transaksi QRIS topup dari DB lokal, saldo cache, dan estimasi settlement per toko.',
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'status' => $status,
            'settlementStatus' => $settlementStatus,
            'search' => $search,
            'stats' => $stats,
            'transactions' => $transactions,
            'settlementRows' => $settlementRows,
            'settlementStats' => $this->settlementStats($merchant, $rangeStart, $rangeEnd, $search, $settlementStatus),
            'gatewayBalance' => app(\App\Services\GatewayBalanceService::class)->current($merchant),
            'latestSync' => GatewaySyncLog::query()
                ->where('merchant_id', $merchant->id)
                ->where('direction', 'pull')
                ->latest('finished_at')
                ->first(),
        ]);
    }

    private function financeStats(Merchant $merchant, ?CarbonImmutable $rangeStart, ?CarbonImmutable $rangeEnd, string $search): array
    {
        $todayStart = now('Asia/Jakarta')->startOfDay();
        $todayEnd = now('Asia/Jakarta')->endOfDay();
        $today = $this->transactionBaseQuery($merchant, CarbonImmutable::instance($todayStart), CarbonImmutable::instance($todayEnd), $search)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->first();

        if ($search === '') {
            $metrics = MerchantDailyMetric::query()
                ->where('merchant_id', $merchant->id)
                ->when($rangeStart && $rangeEnd, fn ($query) => $query
                    ->whereDate('metric_date', '>=', $rangeStart->toDateString())
                    ->whereDate('metric_date', '<=', $rangeEnd->toDateString()))
                ->selectRaw('COALESCE(SUM(trx_total), 0) as total')
                ->selectRaw('COALESCE(SUM(trx_success), 0) as success')
                ->selectRaw('COALESCE(SUM(trx_pending), 0) as pending')
                ->selectRaw('COALESCE(SUM(trx_expired), 0) as expired')
                ->selectRaw('COALESCE(SUM(amount_success), 0) as gross_success')
                ->selectRaw('COALESCE(SUM(amount_total), 0) as volume_total')
                ->selectRaw('COALESCE(SUM(net_success), 0) as net_success')
                ->selectRaw('COALESCE(SUM(amount_pending), 0) as pending_amount')
                ->first();

            $grossSuccess = (int) $metrics->gross_success;
            $netSuccess = (int) $metrics->net_success;

            return [
                'total' => (int) $metrics->total,
                'success' => (int) $metrics->success,
                'pending' => (int) $metrics->pending,
                'expired' => (int) $metrics->expired,
                'gross_success' => $grossSuccess,
                'volume_total' => (int) $metrics->volume_total,
                'net_success' => $netSuccess,
                'fee_amount' => max(0, $grossSuccess - $netSuccess),
                'pending_amount' => (int) $metrics->pending_amount,
                'today_total' => (int) $today->total,
                'today_volume' => (int) $today->volume,
            ];
        }

        $row = $this->transactionBaseQuery($merchant, $rangeStart, $rangeEnd, $search)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as expired")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as gross_success")
            ->selectRaw('COALESCE(SUM(amount), 0) as volume_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN net_amount ELSE 0 END), 0) as net_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN fee_amount ELSE 0 END), 0) as fee_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->first();

        return [
            'total' => (int) $row->total,
            'success' => (int) $row->success,
            'pending' => (int) $row->pending,
            'expired' => (int) $row->expired,
            'gross_success' => (int) $row->gross_success,
            'volume_total' => (int) $row->volume_total,
            'net_success' => (int) $row->net_success,
            'fee_amount' => (int) $row->fee_amount,
            'pending_amount' => (int) $row->pending_amount,
            'today_total' => (int) $today->total,
            'today_volume' => (int) $today->volume,
        ];
    }

    private function settlementStats(Merchant $merchant, ?CarbonImmutable $rangeStart, ?CarbonImmutable $rangeEnd, string $search, string $settlementStatus): array
    {
        $row = MerchantSettlement::query()
            ->where('merchant_id', $merchant->id)
            ->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('settlement_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]))
            ->when($settlementStatus !== 'all', fn ($query) => $query->where('status', $settlementStatus))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('reference', 'like', "{$search}%")
                    ->orWhere('batch_name', 'like', "{$search}%")
                    ->orWhere('status', 'like', "{$search}%");
            }))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(trx_count), 0) as trx_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(total_fee), 0) as total_fee')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as net_amount')
            ->first();

        return [
            'total' => (int) $row->total,
            'trx_count' => (int) $row->trx_count,
            'total_amount' => (int) $row->total_amount,
            'total_fee' => (int) $row->total_fee,
            'net_amount' => (int) $row->net_amount,
        ];
    }

}
