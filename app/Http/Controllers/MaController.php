<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantGatewayBalance;
use App\Models\MerchantRegistration;
use App\Models\MerchantSettlement;
use App\Models\MerchantTicket;
use App\Models\MerchantWithdrawal;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use App\Rules\ExactlyOneFeeMenuFilled;
use App\Rules\FeeMenuRatesAboveFloor;
use App\Rules\FeeMenuRatesAboveReference;
use App\Services\AuditLogService;
use App\Services\FeeMenuCatalog;
use App\Services\FeeSyncService;
use App\Services\TelegramBotMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaController extends Controller
{
    /**
     * Request-scoped memoization for merchantVolumeForWindow() - see that
     * method's docblock.
     */
    private $merchantVolumeForWindowCacheKey = null;

    private $merchantVolumeForWindowCache = null;

    /**
     * Every MA tab used to eagerly compute all of the below regardless of which
     * tab was actually being viewed (~50+ queries per request even for a page
     * like "Create Toko" that needs none of the report/summary data). Each
     * block below only runs for the tab(s) that actually render it — see
     * resources/views/paygrid/ma.blade.php for the per-$active variable usage
     * this mirrors.
     */
    public function page(string $page = 'overview'): View
    {
        $feeMenus = app(FeeMenuCatalog::class);
        abort_unless(in_array($page, ['overview', 'report', 'fee', 'approval', 'mapping', 'stores', 'agents', 'create-store', 'bot-monitoring', 'analytics'], true), 404);

        $filters = $this->filters();
        $dataFilters = in_array($page, ['overview', 'report', 'fee', 'analytics'], true) ? $this->periodFilters($filters) : $filters;

        $selectedAgent = $page === 'report' ? $this->selectedAgent($filters) : null;
        $selectedStore = $page === 'report' ? $this->selectedStore($filters) : null;

        $merchants = in_array($page, ['fee', 'mapping', 'stores'], true) ? $this->merchants($filters)->get() : collect();
        if ($page === 'fee') {
            $feeAmounts = $this->feeAmountsByMerchant($dataFilters);
            $merchants->each(function ($m) use ($feeAmounts) {
                $amounts = $feeAmounts->get($m->id);
                $m->merchant_fee_amount = (int) round((float) ($amounts->merchant_fee ?? 0));
                $m->agent_fee_amount = (int) round((float) ($amounts->agent_fee ?? 0));
                $m->ma_fee_amount = (int) round((float) ($amounts->ma_fee ?? 0));
                $m->volume_trx = (int) ($amounts->volume ?? 0);
            });
        }
        if ($page === 'stores') {
            $activity = $this->storeActivityByMerchant($merchants->pluck('id'));
            $merchants->each(function ($m) use ($activity) {
                $row = $activity->get($m->id);
                $m->activity_trx_total = (int) ($row?->trx_total ?? 0);
                $m->activity_volume_success = (int) ($row?->volume_success ?? 0);
                $m->activity_last_transaction_at = $row?->last_transaction_at ? CarbonImmutable::parse($row->last_transaction_at) : null;
            });
        }

        $overviewMerchants = $page === 'overview' ? $this->merchants($dataFilters)->get() : null;
        $overviewDailySeries = $page === 'overview'
            ? $this->cachedAnalytics('overview-daily-series', $dataFilters, fn () => $this->dailyMetricSeries($overviewMerchants->pluck('id'), 30))
            : null;
        $overviewQuickStats = $overviewDailySeries ? $this->overviewQuickStats($overviewDailySeries) : null;
        $overviewDisbursement = $overviewDailySeries ? $this->overviewDisbursement($overviewDailySeries) : null;

        if ($page === 'overview') {
            $overviewTopMerchants = $this->topMerchants($this->todayFilters(), $overviewMerchants, collect($this->cachedAnalytics('overview-top-merchants-today', $this->todayFilters(), fn () => $this->merchantSuccessTotals($this->todayFilters(), $overviewMerchants->pluck('id')))));
            $withdrawals = collect($this->cachedAnalytics('overview-withdrawals-today', $this->todayFilters(), fn () => $this->withdrawalTotalsToday($overviewTopMerchants->pluck('merchant'))));
            $overviewTopMerchants->each(function ($row) use ($withdrawals) {
                $row->withdrawal = $withdrawals[$row->merchant_id] ?? 0;
            });
        } else {
            $overviewTopMerchants = collect();
        }

        return view('paygrid.ma', [
            'roleLabel' => 'MA',
            'menus' => app(\App\Services\Navigation\MenuBuilder::class)->ma(),
            'active' => $page,
            'filters' => $filters,
            'dataFilters' => $dataFilters,
            'periodLabel' => $this->periodLabel($dataFilters),
            'agents' => $page === 'agents'
                ? $this->agents($filters)->when($filters['status'] === 'all', fn ($query) => $query->where('is_active', true))->get()
                : collect(),
            'allAgents' => in_array($page, ['report', 'fee', 'stores', 'mapping', 'create-store', 'analytics'], true)
                ? $this->agents($this->blankFilters())->get()
                : collect(),
            'selectedAgent' => $selectedAgent,
            'selectedStore' => $selectedStore,
            'selectedAgentStores' => $selectedAgent ? $this->selectedAgentStores($dataFilters) : collect(),
            'merchants' => $merchants,
            'registrations' => $page === 'approval' ? $this->registrations($filters)->get() : collect(),
            'transactions' => $page === 'report' ? $this->transactions($dataFilters)->simplePaginate(25)->withQueryString() : null,
            'selectedStoreStats' => $page === 'report' ? $this->selectedStoreStats($dataFilters) : null,
            'summary' => match ($page) {
                'overview' => $this->cachedAnalytics('overview-summary', $dataFilters, fn () => $this->summary($dataFilters)),
                'fee' => $this->summary($dataFilters),
                default => [],
            },
            'reportAgents' => $page === 'report' ? $this->reportAgents($dataFilters) : collect(),
            'topMerchants' => $overviewTopMerchants,
            'topPaymentSources' => $page === 'overview'
                ? collect($this->cachedAnalytics('overview-top-payment-sources', $this->todayFilters(), fn () => $this->topPaymentSources($overviewMerchants->pluck('id'))))
                : collect(),
            'withdrawalsCompletedByBank' => $page === 'overview'
                ? collect($this->cachedAnalytics('overview-withdrawals-completed-by-bank', $this->todayFilters(), fn () => $this->withdrawalsByBank($overviewMerchants->pluck('id'), 'COMPLETED')))
                : collect(),
            'withdrawalsPendingByBank' => $page === 'overview'
                ? collect($this->cachedAnalytics('overview-withdrawals-pending-by-bank', $this->todayFilters(), fn () => $this->withdrawalsByBank($overviewMerchants->pluck('id'), 'PENDING')))
                : collect(),
            'overviewDailySeries' => $overviewDailySeries ?? [],
            'overviewHourlyFlow' => $page === 'overview'
                ? $this->cachedAnalytics('overview-hourly-flow', $dataFilters, fn () => $this->overviewHourlyFlow($overviewMerchants->pluck('id')))
                : [],
            'overviewQuickStats' => $overviewQuickStats ?? [],
            'overviewDisbursement' => $overviewDisbursement ?? [],
            'maNotifications' => request()->user()?->unreadNotifications()->latest()->limit(5)->get() ?? collect(),
            'botMonitoring' => $page === 'bot-monitoring'
                ? app(TelegramBotMonitoringService::class)->data($this->botMonitoringFilters(), request()->boolean('refresh'))
                : null,
            'pendingIpWhitelist' => $page === 'bot-monitoring'
                ? app(TelegramBotMonitoringService::class)->pendingIpWhitelist()
                : collect(),
            'analyticsBisnis' => $page === 'analytics' ? $this->cachedAnalytics('bisnis', $dataFilters, fn () => $this->analyticsBisnis($dataFilters)) : [],
            'analyticsAgentLeaderboard' => $page === 'analytics' ? $this->cachedAnalytics('agent-leaderboard', $dataFilters, fn () => $this->analyticsAgentLeaderboard($dataFilters)) : [],
            'analyticsRevenueConcentration' => $page === 'analytics' ? $this->cachedAnalytics('revenue-concentration', $dataFilters, fn () => $this->analyticsRevenueConcentration($dataFilters)) : [],
            'analyticsAmountDistribution' => $page === 'analytics' ? $this->cachedAnalytics('amount-distribution', $dataFilters, fn () => $this->analyticsAmountDistribution($dataFilters)) : [],
            'analyticsVolumeProjection' => $page === 'analytics' ? $this->cachedAnalytics('volume-projection', [], fn () => $this->analyticsVolumeProjection()) : [],
            'analyticsSettlementReconciliation' => $page === 'analytics' ? $this->cachedAnalytics('settlement-reconciliation', $dataFilters, fn () => $this->analyticsSettlementReconciliation($dataFilters)) : [],
            'hourlyTrafficFilters' => $page === 'analytics' ? $this->hourlyTrafficFilters() : [],
            'analyticsHourlyTraffic' => $page === 'analytics'
                ? $this->cachedAnalytics('hourly-traffic', $this->hourlyTrafficFilters(), fn () => $this->analyticsHourlyTraffic($this->hourlyTrafficFilters()))
                : [],
            'groupedMerchantsForAnalytics' => $page === 'analytics' ? $this->groupedMerchantsForAnalytics() : collect(),
            'feeMenus' => $feeMenus,
        ]);
    }

    public function analyticsTab(string $tab): View
    {
        abort_unless(in_array($tab, ['performance', 'operations'], true), 404);

        $dataFilters = $this->periodFilters($this->filters());

        if ($tab === 'performance') {
            return view('paygrid.partials.ma-analytics-performance', [
                'periodLabel' => $this->periodLabel($dataFilters),
                'analyticsPerformance' => $this->cachedAnalytics('performance', $dataFilters, fn () => $this->analyticsPerformance($dataFilters)),
                'analyticsLatency' => $this->cachedAnalytics('latency', $dataFilters, fn () => $this->analyticsLatency($dataFilters)),
                'analyticsChannelReliability' => $this->cachedAnalytics('channel-reliability', $dataFilters, fn () => $this->analyticsChannelReliability($dataFilters)),
            ]);
        }

        return view('paygrid.partials.ma-analytics-operations', [
            'periodLabel' => $this->periodLabel($dataFilters),
            'analyticsOperations' => $this->cachedAnalytics('operations', $dataFilters, fn () => $this->analyticsOperations($dataFilters)),
            'analyticsOutlierDetection' => $this->cachedAnalytics('outlier-detection', [], fn () => $this->analyticsOutlierDetection()),
            'analyticsTicketSla' => $this->cachedAnalytics('ticket-sla', $dataFilters, fn () => $this->analyticsTicketSla($dataFilters)),
            'analyticsAccountActivity' => $this->cachedAnalytics('account-activity', [], fn () => $this->analyticsAccountActivity()),
            'analyticsProvisioningFailures' => $this->cachedAnalytics('provisioning-failures', [], fn () => $this->analyticsProvisioningFailures()),
            'analyticsHealthScore' => $this->cachedAnalytics('health-score', [], fn () => $this->analyticsHealthScore()),
        ]);
    }

    public function export(Request $request): Response
    {
        $rows = $this->transactions($this->periodFilters($this->filters()))->limit(5000)->get();
        $csv = "Masuk,Sukses,Durasi,Toko,Agen,Status,Amount,Reference,RRN,Payment ID,Net,Settlement\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', [
                $row->submitted_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                $row->succeeded_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
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

    public function storeAgent(Request $request, AuditLogService $audit, FeeMenuCatalog $feeMenus): RedirectResponse
    {
        $request->merge(['connection_type' => $request->input('connection_type', 'cm')]);
        $typeCategory = $feeMenus->typeCategory((string) $request->input('connection_type'));
        $request->merge(['fee_menu_rates' => $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'agent')]);
        $maRates = (array) (auth()->user()->fee_menu_rates ?? []);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'contact' => ['nullable', 'string', 'max:80'],
            'status' => ['required', 'in:Active,Review,Suspended'],
            'connection_type' => ['required', 'in:cm,script'],
            'engine_type' => [Rule::requiredIf($typeCategory === 'engine'), 'nullable', 'in:sc,api'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('agent', null), new FeeMenuRatesAboveReference('agent', $maRates, 'Based Fee MA')],
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
            'connection_type' => $data['connection_type'],
            'engine_type' => $data['engine_type'] ?? null,
            'fee_menu_rates' => $data['fee_menu_rates'],
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

        $csUser = User::query()->create([
            'name' => 'CS '.$agent->name,
            'email' => 'cs-'.strtolower($agent->code).'@paygrid.local',
            'username' => 'CS-'.$agent->code,
            'role' => 'cs_agent',
            'agent_id' => $agent->id,
            'is_active' => $isActive,
            'password' => Hash::make($password),
            'plain_password' => $password,
        ]);
        $audit->record('ma.cs_agent_created', $csUser, null, $csUser->only(['email', 'username', 'role']));

        return back()->with('status', 'Agen lokal berhasil dibuat. Kode login: '.$agent->code.'. Password: '.$password.'. Belum dikirim ke HG. Akun CS Agent — Username: '.$csUser->username.', Password: '.$password.'.');
    }

    public function mapAgent(Request $request, Merchant $merchant, AuditLogService $audit, FeeSyncService $feeSync): RedirectResponse
    {
        abort_unless($this->canUseMerchant($merchant), 403);
        $data = $request->validate(['agent_id' => ['required', 'exists:agents,id']]);
        $agent = Agent::query()->with('ma')->findOrFail($data['agent_id']);
        abort_unless($this->canUseAgent($agent), 403);
        $before = $merchant->only(['agent_id', 'merchant_group_name', 'merchant_group_id', 'agent_fee_percent', 'ma_fee_percent']);
        $update = [
            'agent_id' => $agent->id,
            'merchant_group_name' => $agent->name,
            'merchant_group_id' => $agent->hg_group_id,
        ];
        if ($merchant->fee_menu) {
            $update['agent_fee_percent'] = $feeSync->agentRateFor($agent, $merchant->fee_menu);
            $update['ma_fee_percent'] = $feeSync->maRateFor($agent->ma, $merchant->fee_menu);
        }
        $merchant->forceFill($update)->save();
        $audit->record('ma.merchant_agent_mapped', $merchant, $before, $merchant->only(array_keys($before)));

        return back()->with('status', 'Agen toko berhasil disimpan.');
    }

    public function storeMerchant(Request $request, AuditLogService $audit, FeeMenuCatalog $feeMenus, FeeSyncService $feeSync): RedirectResponse
    {
        $typeCategory = $feeMenus->typeCategory((string) $request->input('merchant_type'));
        $rates = $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'merchant');
        $request->merge(['fee_menu_rates' => $rates]);
        $agentRates = (array) (Agent::find($request->input('agent_id'))?->fee_menu_rates ?? []);
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
            'engine_type' => [Rule::requiredIf($typeCategory === 'engine'), 'nullable', 'in:sc,api'],
            'merchant_id' => ['nullable', 'string', 'max:160'],
            'merchant_key' => ['nullable', 'string', 'max:255'],
            'transaction_callback_url' => ['nullable', 'url', 'max:255'],
            'withdrawal_callback_url' => ['nullable', 'url', 'max:255'],
            'api_ip_whitelist' => ['nullable', 'string', 'max:255'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('merchant', null), new ExactlyOneFeeMenuFilled(), new FeeMenuRatesAboveReference('merchant', $agentRates, 'Based Fee Agent')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $data['fee_menu'] = array_key_first(array_filter($rates));
        $data['merchant_mdr_percent'] = $rates[$data['fee_menu']];
        $data['settlement_method'] = $feeMenus->settlementMethod($data['fee_menu']);
        $slug = $this->uniqueMerchantSlug($data['name']);
        $agent = Agent::query()->with('ma')->findOrFail($data['agent_id']);
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
            'engine_type' => $data['engine_type'] ?? null,
            'gateway' => $data['gateway'],
            'approval_status' => 'approved',
            'topup_enabled' => $data['merchant_type'] === 'cm',
            'topup_url' => $data['merchant_type'] === 'cm' ? route('topup', ['merchant' => $slug]) : null,
            'transaction_callback_url' => $data['transaction_callback_url'] ?? url('/api/callbacks/hilogate/transaction'),
            'withdrawal_callback_url' => $data['withdrawal_callback_url'] ?? null,
            'pic_email' => $data['pic_email'] ?? null,
            'pic_telegram' => $data['pic_telegram'] ?? null,
            'fee_menu' => $data['fee_menu'],
            'fee_menu_rates' => $data['fee_menu_rates'],
            'settlement_method' => $data['settlement_method'],
            ...$feeSync->snapshotFor($agent, $data['fee_menu'], $data['merchant_mdr_percent']),
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

    public function updateAgentFee(Request $request, Agent $agent, AuditLogService $audit, FeeMenuCatalog $feeMenus, FeeSyncService $feeSync): RedirectResponse
    {
        abort_unless($this->canUseAgent($agent), 403);
        $request->merge(['fee_menu_rates' => $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'agent')]);
        $maRates = (array) ($agent->ma->fee_menu_rates ?? []);
        $data = $request->validate([
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('agent', null), new FeeMenuRatesAboveReference('agent', $maRates, 'Based Fee MA')],
        ]);
        $before = $agent->only(['fee_menu_rates']);
        $agent->forceFill($data)->save();
        $feeSync->resyncAgent($agent);
        $audit->record('ma.agent_fee_updated', $agent, $before, $agent->only(array_keys($before)));

        return back()->with('status', 'Fee agen berhasil disimpan.');
    }

    public function updateStoreFee(Request $request, Merchant $merchant, AuditLogService $audit, FeeMenuCatalog $feeMenus, FeeSyncService $feeSync): RedirectResponse
    {
        abort_unless($this->canUseMerchant($merchant), 403);
        $this->normalizePercentInputs($request, ['payin_fee_percent']);
        $rates = $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'merchant');
        $request->merge(['fee_menu_rates' => $rates]);
        $agent = $merchant->agent()->with('ma')->firstOrFail();
        $agentRates = (array) ($agent->fee_menu_rates ?? []);
        $data = $request->validate([
            'payin_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('merchant', null), new ExactlyOneFeeMenuFilled(), new FeeMenuRatesAboveReference('merchant', $agentRates, 'Based Fee Agent')],
        ]);
        $data['fee_menu'] = array_key_first(array_filter($rates));
        $data['settlement_method'] = $feeMenus->settlementMethod($data['fee_menu']);
        $data = array_merge($data, $feeSync->snapshotFor($agent, $data['fee_menu'], $rates[$data['fee_menu']]));
        $before = $merchant->only(['merchant_mdr_percent', 'payin_fee_percent', 'fee_menu', 'fee_menu_rates', 'settlement_method']);
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

    private function botMonitoringFilters(): array
    {
        return [
            'status' => trim((string) request('bot_status', '')),
            'category' => trim((string) request('bot_category', '')),
            'assigned_name' => trim((string) request('bot_assigned', '')),
            'from' => request('bot_from'),
            'to' => request('bot_to'),
            'q' => trim((string) request('bot_q', '')),
        ];
    }

    private function blankFilters(): array
    {
        return ['q' => '', 'status' => 'all', 'agent_id' => 'all', 'store_id' => 'all', 'agents_view' => 'top', 'period' => 'this_month', 'type' => 'all', 'from' => null, 'to' => null];
    }

    private function todayFilters(): array
    {
        $today = now('Asia/Jakarta')->toDateString();

        return array_merge($this->blankFilters(), ['from' => $today, 'to' => $today]);
    }

    private function periodFilters(array $filters): array
    {
        if ($filters['period'] === 'all') {
            return array_merge($filters, ['from' => '', 'to' => '']);
        }

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
        return Merchant::query()->with('agent.ma')
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

    /**
     * Merchant scoping (MA ownership + agent filter) is resolved against the small
     * merchants/agents tables first, then applied to topup_requests as a plain
     * whereIn on merchant_id. This keeps the hot report query a single-table scan
     * ordered by submitted_at (index-only), instead of forcing MySQL to join
     * topup_requests to merchants/agents before it can sort — which drives the
     * optimizer to build a temp table + filesort over the whole matched set.
     */
    private function transactionsQuery(array $filters)
    {
        $maId = $this->currentMaId();

        $scopedMerchantIds = ($maId || $filters['agent_id'] !== 'all')
            ? Merchant::query()
                ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
                ->when($filters['agent_id'] !== 'all', fn ($query) => $query->where('agent_id', $filters['agent_id']))
                ->pluck('id')
            : null;

        $searchMerchantIds = $filters['q'] !== ''
            ? Merchant::query()
                ->when($scopedMerchantIds !== null, fn ($query) => $query->whereIn('id', $scopedMerchantIds))
                ->where('name', 'like', $filters['q'].'%')
                ->pluck('id')
            : null;

        return TopupRequest::query()
            ->when($scopedMerchantIds !== null, fn ($query) => $query->whereIn('topup_requests.merchant_id', $scopedMerchantIds))
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('topup_requests.payment_id', 'like', $filters['q'].'%')->orWhere('topup_requests.rrn', 'like', $filters['q'].'%')->orWhere('topup_requests.customer_reference', 'like', $filters['q'].'%')->orWhereIn('topup_requests.merchant_id', $searchMerchantIds)))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('topup_requests.status', $filters['status']))
            ->when($filters['store_id'] !== 'all', fn ($query) => $query->where('topup_requests.merchant_id', $filters['store_id']));
    }

    private function transactions(array $filters)
    {
        return $this->transactionsQuery($filters)
            ->select('topup_requests.*')
            ->with(['merchant.agent', 'feeSnapshot'])
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
        // All-time, not status/period filtered - a store's "last active" shouldn't
        // disappear just because its only recent attempt failed or fell outside
        // the report's selected date range.
        $lastActivity = TopupRequest::query()
            ->whereIn('merchant_id', $stores->pluck('id'))
            ->selectRaw('merchant_id, MAX(submitted_at) as last_transaction_at')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        return $stores
            ->each(function (Merchant $store) use ($totals, $lastActivity): void {
                $row = $totals->get($store->id);
                $store->metric_trx_total = (int) ($row?->trx_total ?? 0);
                $store->metric_volume_success = (int) ($row?->volume_success ?? 0);
                $lastAt = $lastActivity->get($store->id)?->last_transaction_at;
                $store->metric_last_transaction_at = $lastAt ? CarbonImmutable::parse($lastAt) : null;
            })
            ->sortByDesc('metric_trx_total')
            ->values();
    }

    private function summary(array $filters): array
    {
        $totals = (clone $this->transactionsQuery($filters))
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'success' THEN 1 ELSE 0 END) as trx_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'success' THEN topup_requests.amount ELSE 0 END), 0) as volume_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'success' THEN topup_requests.net_amount ELSE 0 END), 0) as total_settlement")
            ->selectRaw("COALESCE(SUM(CASE WHEN topup_requests.status = 'pending' THEN topup_requests.amount ELSE 0 END), 0) as pending_transaction_amount")
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'pending' THEN 1 ELSE 0 END) as trx_pending")
            ->selectRaw("SUM(CASE WHEN topup_requests.status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as trx_expired")
            ->first();
        $fee = $this->feeTotalsForFilters($filters);
        $hgSettlement = $this->hgSettlements($filters)->sum('net_amount');
        $balances = MerchantGatewayBalance::query()
            ->whereIn('merchant_id', $this->merchants($filters)->pluck('id'))
            ->selectRaw('COALESCE(SUM(active_balance), 0) as active, COALESCE(SUM(pending_balance), 0) as pending')
            ->first();
        $trxTotal = (int) ($totals->trx_total ?? 0);
        $trxPending = (int) ($totals->trx_pending ?? 0);
        $trxExpired = (int) ($totals->trx_expired ?? 0);
        $trxAttempted = $trxTotal + $trxPending + $trxExpired;

        return [
            'volume_success' => (int) ($totals->volume_success ?? 0),
            'pending_transaction_amount' => (int) ($totals->pending_transaction_amount ?? 0),
            'total_settlement' => (int) ($totals->total_settlement ?? 0),
            'hg_settlement' => (int) $hgSettlement,
            'trx_total' => $trxTotal,
            'trx_pending' => $trxPending,
            'trx_expired' => $trxExpired,
            'success_rate' => $trxAttempted > 0 ? round($trxTotal / $trxAttempted * 100, 2) : 0,
            'issue_total' => $this->ticketQuery($filters)->count(),
            'issue_solved' => $this->ticketQuery($filters)->where('status', 'done')->count(),
            'agent_total' => $this->agents($filters)->count(),
            'merchant_total' => $this->merchants($filters)->count(),
            'unassigned' => $this->merchants($filters)->whereNull('agent_id')->count(),
            'fee_ma' => $fee['ma'],
            'fee_agent' => $fee['agent'],
            'available_balance' => (int) ($balances->active ?? 0),
            'pending_settlement' => (int) ($balances->pending ?? 0),
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
            ->selectRaw("merchants.agent_id, COALESCE(SUM(CASE WHEN topup_requests.status = 'success' THEN topup_requests.amount ELSE 0 END), 0) as volume")
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
        $row = (clone $this->transactionsQuery($filters))
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

    private function feeTotalsForFilters(array $filters): array
    {
        $volumesByMerchant = (clone $this->transactionsQuery(array_merge($filters, ['status' => 'success'])))
            ->selectRaw('topup_requests.merchant_id as merchant_id')
            ->selectRaw('COALESCE(SUM(topup_requests.amount), 0) as volume')
            ->groupBy('topup_requests.merchant_id')
            ->get();

        $percents = Merchant::query()
            ->whereIn('id', $volumesByMerchant->pluck('merchant_id'))
            ->get(['id', 'agent_fee_percent', 'ma_fee_percent', 'merchant_mdr_percent'])
            ->keyBy('id');

        $ma = $agent = $merchant = 0.0;
        foreach ($volumesByMerchant as $row) {
            $percent = $percents->get($row->merchant_id);
            if (! $percent) {
                continue;
            }
            $ma += $row->volume * ($percent->agent_fee_percent - $percent->ma_fee_percent) / 100;
            $agent += $row->volume * ($percent->merchant_mdr_percent - $percent->agent_fee_percent) / 100;
            $merchant += $row->volume * $percent->merchant_mdr_percent / 100;
        }

        return [
            'ma' => (int) round($ma),
            'agent' => (int) round($agent),
            'merchant' => (int) round($merchant),
        ];
    }

    private function feeAmountsByMerchant(array $filters)
    {
        $volumesByMerchant = (clone $this->transactionsQuery(array_merge($filters, ['status' => 'success'])))
            ->selectRaw('topup_requests.merchant_id as merchant_id')
            ->selectRaw('COALESCE(SUM(topup_requests.amount), 0) as volume')
            ->groupBy('topup_requests.merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $percents = Merchant::query()
            ->whereIn('id', $volumesByMerchant->keys())
            ->get(['id', 'agent_fee_percent', 'ma_fee_percent', 'merchant_mdr_percent'])
            ->keyBy('id');

        return $volumesByMerchant->map(function ($row) use ($percents) {
            $percent = $percents->get($row->merchant_id);
            $row->merchant_fee = $percent ? $row->volume * $percent->merchant_mdr_percent / 100 : 0;
            $row->agent_fee = $percent ? $row->volume * ($percent->merchant_mdr_percent - $percent->agent_fee_percent) / 100 : 0;
            $row->ma_fee = $percent ? $row->volume * ($percent->agent_fee_percent - $percent->ma_fee_percent) / 100 : 0;

            return $row;
        });
    }

    /**
     * All-time activity for the List Toko page: trx count/volume only count
     * status=success (matches the trx-count convention used elsewhere, e.g.
     * selectedAgentStores()), but last_transaction_at is intentionally NOT
     * status-filtered — a store that's only getting pending/failed attempts
     * should still show as recently "active" rather than looking dormant.
     */
    private function storeActivityByMerchant($merchantIds)
    {
        return TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->selectRaw('merchant_id')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as trx_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume_success")
            ->selectRaw('MAX(submitted_at) as last_transaction_at')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');
    }

    private function hgSettlements(array $filters)
    {
        return MerchantSettlement::query()
            ->where('gateway', 'hilogate')
            ->whereIn('status', ['APPROVED', 'SUCCESS', 'DONE', 'SETTLED'])
            ->when($filters['from'], fn ($query) => $query->whereDate('settlement_date', '>=', $this->wibDate($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->whereDate('settlement_date', '<=', $this->wibDate($filters['to'])))
            ->whereHas('merchant', function ($query) use ($filters): void {
                $query
                    ->when($this->currentMaId(), fn ($nested, $maId) => $nested->whereRelation('agent', 'ma_user_id', $maId))
                    ->when($filters['q'] !== '', fn ($nested) => $nested->where(fn ($search) => $search->where('name', 'like', $filters['q'].'%')->orWhere('slug', 'like', $filters['q'].'%')->orWhere('merchant_id', 'like', $filters['q'].'%')->orWhereRelation('agent', 'name', 'like', $filters['q'].'%')))
                    ->when($filters['agent_id'] !== 'all', fn ($nested) => $nested->where('agent_id', $filters['agent_id']))
                    ->when($filters['store_id'] !== 'all', fn ($nested) => $nested->whereKey($filters['store_id']))
                    ->when($filters['type'] !== 'all', fn ($nested) => $nested->where('merchant_type', $filters['type']));
            });
    }

    private function cachedAnalytics(string $key, array $filters, \Closure $callback): array
    {
        // 'to' for relative periods (e.g. "this_month") resolves to now() fresh on
        // every request, which would make almost every request a unique cache key.
        // Bucket from/to to the nearest 3-minute window (matching the TTL below) so
        // requests landing in the same window actually share a cached result.
        $bucketed = $filters;
        foreach (['from', 'to'] as $field) {
            if (! empty($bucketed[$field])) {
                $ts = strtotime($bucketed[$field]);
                $bucketed[$field] = date('Y-m-d H:i:s', $ts - ($ts % 180));
            }
        }
        $cacheKey = 'ma-analytics:'.$this->currentMaId().':'.$key.':'.md5(json_encode($bucketed));

        // Normalize to plain arrays before caching: the database cache driver's
        // serialize()/unserialize() round-trip does not reliably reconstruct
        // Collections of stdClass query rows, producing "incomplete object"
        // data once a cache entry is read back warm.
        return Cache::remember($cacheKey, 180, fn () => json_decode(json_encode($callback()), true));
    }

    /**
     * Merchant IDs scoped to the current MA, resolved from the small merchants/
     * agents tables. Used in place of whereRelation('merchant.agent', ...) directly
     * on a hot table (topup_requests/support_tickets) - see transactionsQuery()'s
     * docblock for why that join kills index usage at this table size. Returns
     * null when there's no MA scoping to apply (e.g. superadmin).
     */
    private function maScopedMerchantIds()
    {
        $maId = $this->currentMaId();

        return $maId ? Merchant::query()->whereRelation('agent', 'ma_user_id', $maId)->pluck('id') : null;
    }

    /**
     * Per-merchant success volume for a date window, scoped to $merchantIds
     * (null = no scoping). analyticsAgentLeaderboard()'s current-window figures
     * and analyticsRevenueConcentration() group the exact same merchant set
     * over the exact same window - memoized per request so that when both run
     * in the same Analytics page load (both cache misses), the ~130k-row scan
     * only happens once instead of twice.
     */
    private function merchantVolumeForWindow($merchantIds, ?CarbonImmutable $from, CarbonImmutable $to)
    {
        $key = json_encode([$merchantIds?->values()->all(), $from?->toIso8601String(), $to->toIso8601String()]);

        if ($this->merchantVolumeForWindowCacheKey === $key) {
            return $this->merchantVolumeForWindowCache;
        }

        $rows = TopupRequest::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->where('status', 'success')
            ->when($from, fn ($query) => $query->where('submitted_at', '>=', $from))
            ->where('submitted_at', '<=', $to)
            ->selectRaw('merchant_id, SUM(amount) as volume')
            ->groupBy('merchant_id')
            ->get();

        $this->merchantVolumeForWindowCacheKey = $key;
        $this->merchantVolumeForWindowCache = $rows;

        return $rows;
    }

    private function analyticsBisnis(array $filters): array
    {
        $merchantIds = $this->maScopedMerchantIds();
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $dateExpr = $isSqlite ? "date(submitted_at, '+7 hours')" : "DATE(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";

        $rows = TopupRequest::query()
            ->where('status', 'success')
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->when($filters['from'], fn ($query) => $query->where('submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw("{$dateExpr} as d")
            ->selectRaw('COALESCE(SUM(amount), 0) as gmv')
            ->selectRaw('COALESCE(SUM(fee_amount), 0) as fee')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $totalGmv = (int) $rows->sum('gmv');
        $totalFee = (int) $rows->sum('fee');

        return [
            'labels' => $rows->pluck('d')->values(),
            'gmv' => $rows->pluck('gmv')->map(fn ($v) => (int) $v)->values(),
            'feeDaily' => $rows->pluck('fee')->map(fn ($v) => (int) $v)->values(),
            'takeRate' => $rows->map(fn ($r) => $r->gmv > 0 ? round(($r->fee / $r->gmv) * 100, 3) : 0)->values(),
            'totalGmv' => $totalGmv,
            'totalFee' => $totalFee,
            'overallTakeRate' => $totalGmv > 0 ? round(($totalFee / $totalGmv) * 100, 3) : 0,
        ];
    }

    private function hourlyTrafficFilters(): array
    {
        return [
            'agent_id' => (string) request('hourly_agent_id', 'all'),
            'merchant_id' => (string) request('hourly_merchant_id', 'all'),
            'mode' => request('hourly_mode') === 'hours' ? 'hours' : 'days',
            'date' => (string) request('hourly_date', now('Asia/Jakarta')->toDateString()),
            'from' => (string) request('hourly_from', now('Asia/Jakarta')->subDays(6)->toDateString()),
            'to' => (string) request('hourly_to', now('Asia/Jakarta')->toDateString()),
        ];
    }

    /**
     * Grouped by calendar day/hour in Asia/Jakarta, not UTC - submitted_at is stored
     * in UTC, so bucketing would otherwise split a single WIB day's transactions
     * across two UTC dates (or two WIB hours across one UTC hour). Aggregated in SQL
     * (not by loading every row into PHP) so this stays cheap even for a wide date
     * range across every merchant - see analyticsHeatmap() for the same pattern.
     * MA-scoping resolves merchant ids from the small merchants/agents tables first
     * (whereIn), rather than a whereRelation EXISTS join on the hot topup_requests
     * table - see transactionsQuery()'s docblock for why that matters at this scale.
     */
    private function analyticsHourlyTraffic(array $filters): array
    {
        $merchantIds = null;
        if ($filters['merchant_id'] !== 'all') {
            $merchantIds = [(int) $filters['merchant_id']];
        } elseif ($filters['agent_id'] !== 'all') {
            $merchantIds = Merchant::query()->where('agent_id', $filters['agent_id'])->pluck('id');
        } elseif ($maId = $this->currentMaId()) {
            $merchantIds = Merchant::query()->whereRelation('agent', 'ma_user_id', $maId)->pluck('id');
        }

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $hourExpr = $isSqlite ? "CAST(strftime('%H', submitted_at, '+7 hours') AS INTEGER)" : "HOUR(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";
        $dateExpr = $isSqlite ? "date(submitted_at, '+7 hours')" : "DATE(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";

        $base = TopupRequest::query()
            ->where('status', 'success')
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds));

        if ($filters['mode'] === 'hours') {
            $date = $filters['date'] ?: now('Asia/Jakarta')->toDateString();
            $start = CarbonImmutable::parse($date, 'Asia/Jakarta')->startOfDay();
            $end = $start->addDay();

            $rows = (clone $base)
                ->where('submitted_at', '>=', $start->utc())
                ->where('submitted_at', '<', $end->utc())
                ->selectRaw("{$hourExpr} as bucket")
                ->selectRaw('COUNT(*) as trx')
                ->selectRaw('COALESCE(SUM(amount), 0) as amount')
                ->groupBy('bucket')
                ->get()
                ->keyBy('bucket');

            $labels = [];
            $trx = [];
            $amount = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $row = $rows->get($hour);
                $labels[] = sprintf('%02d:00', $hour);
                $trx[] = (int) ($row->trx ?? 0);
                $amount[] = (int) ($row->amount ?? 0);
            }

            return ['mode' => 'hours', 'date' => $date, 'labels' => $labels, 'trx' => $trx, 'amount' => $amount];
        }

        $from = CarbonImmutable::parse($filters['from'] ?: now('Asia/Jakarta')->subDays(6)->toDateString(), 'Asia/Jakarta')->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?: now('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->endOfDay();

        $rows = (clone $base)
            ->where('submitted_at', '>=', $from->utc())
            ->where('submitted_at', '<=', $to->utc())
            ->selectRaw("{$dateExpr} as bucket")
            ->selectRaw('COUNT(*) as trx')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $labels = [];
        $trx = [];
        $amount = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $row = $rows->get($key);
            $labels[] = $key;
            $trx[] = (int) ($row->trx ?? 0);
            $amount[] = (int) ($row->amount ?? 0);
        }

        return ['mode' => 'days', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'labels' => $labels, 'trx' => $trx, 'amount' => $amount];
    }

    private function groupedMerchantsForAnalytics()
    {
        $maId = $this->currentMaId();

        return Merchant::query()
            ->with('agent')
            ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Merchant $merchant) => $merchant->agent?->name ?: 'Lainnya')
            ->map(fn ($group) => $group->map(fn (Merchant $merchant) => ['id' => $merchant->id, 'name' => $merchant->name])->values())
            ->sortKeys();
    }

    private function analyticsAgentLeaderboard(array $filters): array
    {
        $from = $filters['from'] ? $this->rangeStart($filters['from']) : null;
        $to = $filters['to'] ? $this->rangeEnd($filters['to']) : CarbonImmutable::now();

        $prevFrom = null;
        $prevTo = null;
        if ($from) {
            $lengthSeconds = $to->diffInSeconds($from);
            $prevTo = $from->copy()->subSecond();
            $prevFrom = $prevTo->copy()->subSeconds($lengthSeconds);
        }

        $merchantIds = $this->maScopedMerchantIds();
        $agentIdByMerchant = Merchant::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('id', $merchantIds))
            ->pluck('agent_id', 'id');

        $rollUp = function ($byMerchant) use ($agentIdByMerchant) {
            $byAgent = [];
            foreach ($byMerchant as $row) {
                $agentId = $agentIdByMerchant->get($row->merchant_id);
                if (! $agentId) {
                    continue;
                }
                $byAgent[$agentId] = ($byAgent[$agentId] ?? 0) + (int) $row->volume;
            }

            return $byAgent;
        };

        $current = collect($rollUp($this->merchantVolumeForWindow($merchantIds, $from, $to)));
        $previous = $prevFrom ? collect($rollUp($this->merchantVolumeForWindow($merchantIds, $prevFrom, $prevTo))) : collect();

        $agentIds = $current->keys()->merge($previous->keys())->unique();
        $agents = Agent::query()->whereIn('id', $agentIds)->get(['id', 'name'])->keyBy('id');

        $rows = $agentIds->map(function ($id) use ($current, $previous, $agents) {
            $curVol = (int) ($current->get($id) ?? 0);
            $prevVol = (int) ($previous->get($id) ?? 0);
            $growth = $prevVol > 0 ? round((($curVol - $prevVol) / $prevVol) * 100, 2) : ($curVol > 0 ? null : 0);

            return [
                'agent_name' => $agents->get($id)?->name ?? '-',
                'current_volume' => $curVol,
                'previous_volume' => $prevVol,
                'growth_percent' => $growth,
            ];
        })->sortByDesc(fn ($r) => $r['growth_percent'] ?? -1)->values()->take(50);

        return ['rows' => $rows, 'hasPrevious' => (bool) $prevFrom];
    }

    private function analyticsRevenueConcentration(array $filters): array
    {
        $merchantIds = $this->maScopedMerchantIds();
        $from = $filters['from'] ? $this->rangeStart($filters['from']) : null;
        $to = $filters['to'] ? $this->rangeEnd($filters['to']) : CarbonImmutable::now();

        $rows = $this->merchantVolumeForWindow($merchantIds, $from, $to)->sortByDesc('volume')->values();

        $totalVolume = (int) $rows->sum('volume');
        $merchantNames = Merchant::query()->whereIn('id', $rows->take(50)->pluck('merchant_id'))->pluck('name', 'id');
        $top = $rows->take(50)->map(fn ($r) => [
            'merchant_name' => $merchantNames->get($r->merchant_id) ?? '-',
            'volume' => (int) $r->volume,
            'percent' => $totalVolume > 0 ? round(($r->volume / $totalVolume) * 100, 2) : 0,
        ]);

        return [
            'rows' => $top,
            'totalVolume' => $totalVolume,
            'top5Percent' => $totalVolume > 0 ? round(($rows->take(5)->sum('volume') / $totalVolume) * 100, 2) : 0,
            'top10Percent' => $totalVolume > 0 ? round(($rows->take(10)->sum('volume') / $totalVolume) * 100, 2) : 0,
        ];
    }

    private function analyticsAmountDistribution(array $filters): array
    {
        $labels = ['< 50rb', '50rb - 100rb', '100rb - 300rb', '300rb - 1jt', '> 1jt'];
        $caseSql = "CASE
            WHEN topup_requests.amount < 50000 THEN '< 50rb'
            WHEN topup_requests.amount < 100000 THEN '50rb - 100rb'
            WHEN topup_requests.amount < 300000 THEN '100rb - 300rb'
            WHEN topup_requests.amount < 1000000 THEN '300rb - 1jt'
            ELSE '> 1jt' END";

        $merchantIds = $this->maScopedMerchantIds();

        $rows = TopupRequest::query()
            ->where('status', 'success')
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->when($filters['from'], fn ($query) => $query->where('submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw("{$caseSql} as bucket, COUNT(*) as cnt")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return ['rows' => collect($labels)->map(fn ($label) => ['label' => $label, 'count' => (int) ($rows->get($label)->cnt ?? 0)])];
    }

    private function analyticsVolumeProjection(): array
    {
        $from = now('Asia/Jakarta')->subDays(29)->startOfDay()->utc();
        $to = now();
        $merchantIds = $this->maScopedMerchantIds();

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $dateExpr = $isSqlite ? "date(submitted_at, '+7 hours')" : "DATE(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";

        $rows = TopupRequest::query()
            ->where('status', 'success')
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->where('submitted_at', '>=', $from)
            ->where('submitted_at', '<=', $to)
            ->selectRaw("{$dateExpr} as d, COALESCE(SUM(amount), 0) as volume")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $labels = [];
        $daily = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now('Asia/Jakarta')->subDays($i)->toDateString();
            $labels[] = $date;
            $daily[] = (int) ($rows->get($date)->volume ?? 0);
        }

        $movingAverage = [];
        foreach ($daily as $i => $value) {
            if ($i < 6) {
                $movingAverage[] = null;

                continue;
            }
            $window = array_slice($daily, $i - 6, 7);
            $movingAverage[] = (int) round(array_sum($window) / 7);
        }

        $last7DayAvg = count($daily) >= 7 ? array_sum(array_slice($daily, -7)) / 7 : (array_sum($daily) / max(1, count($daily)));

        return [
            'labels' => $labels,
            'daily' => $daily,
            'movingAverage' => $movingAverage,
            'last7DayAvgVolume' => (int) round($last7DayAvg),
            'projectedNextMonthVolume' => (int) round($last7DayAvg * 30),
        ];
    }

    private function analyticsSettlementReconciliation(array $filters): array
    {
        $merchantIds = $this->merchants($this->blankFilters())->pluck('id');

        $settlements = MerchantSettlement::query()
            ->whereIn('merchant_id', $merchantIds)
            ->when($filters['from'], fn ($query) => $query->where('settlement_date', '>=', $this->wibDate($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('settlement_date', '<=', $this->wibDate($filters['to'])))
            ->orderByDesc('settlement_date')
            ->limit(50)
            ->get();

        $rows = $settlements->map(function (MerchantSettlement $settlement) {
            // .utc() is required here: submitted_at is stored in UTC, and a WIB-tagged
            // Carbon bound straight into whereBetween() gets its WIB wall-clock digits
            // compared literally against the UTC column (see rangeStart()'s docblock) -
            // without it, "expected" silently drifted from the batch window by 7 hours.
            $windowFrom = CarbonImmutable::parse($settlement->settlement_date->toDateString().' '.($settlement->batch_from ?: '00:00:00'), 'Asia/Jakarta')->utc();
            $windowTo = CarbonImmutable::parse($settlement->settlement_date->toDateString().' '.($settlement->batch_until ?: '23:59:59'), 'Asia/Jakarta')->utc();

            $expected = (int) TopupRequest::query()
                ->where('merchant_id', $settlement->merchant_id)
                ->where('status', 'success')
                ->whereBetween('submitted_at', [$windowFrom, $windowTo])
                ->sum('net_amount');

            return [
                'merchant_name' => $settlement->merchant_name ?: $settlement->merchant?->name ?: '-',
                'settlement_date' => $settlement->settlement_date->toDateString(),
                'expected' => $expected,
                'actual' => (int) $settlement->net_amount,
                'diff' => $expected - (int) $settlement->net_amount,
            ];
        });

        return ['rows' => $rows];
    }

    private function analyticsPerformance(array $filters): array
    {
        $merchantIds = $this->maScopedMerchantIds();

        $base = TopupRequest::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('topup_requests.merchant_id', $merchantIds))
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])));

        // Aggregated in SQL (not by loading every row into PHP) so this stays cheap
        // even for merchants with hundreds of thousands of transactions.
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $dowExpr = $isSqlite ? "CAST(strftime('%w', topup_requests.submitted_at) AS INTEGER)" : 'DAYOFWEEK(topup_requests.submitted_at) - 1';
        $hourExpr = $isSqlite ? "CAST(strftime('%H', topup_requests.submitted_at) AS INTEGER)" : 'HOUR(topup_requests.submitted_at)';

        $heatRows = (clone $base)
            ->selectRaw("{$dowExpr} as dow, {$hourExpr} as hr, COUNT(*) as cnt")
            ->groupBy('dow', 'hr')
            ->get();

        // dow here is 0=Minggu..6=Sabtu (both strftime '%w' and DAYOFWEEK()-1 agree). Display order Senin..Minggu.
        $dayLabels = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
        $matrix = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($heatRows as $row) {
            $dow = (int) $row->dow;
            $rowIndex = $dow === 0 ? 6 : $dow - 1;
            $matrix[$rowIndex][(int) $row->hr] = (int) $row->cnt;
        }
        $maxCell = max(1, ...array_map('max', $matrix));

        $generated = (clone $base)->count();
        $succeeded = (clone $base)->where('topup_requests.status', 'success')->count();

        return [
            'dayLabels' => $dayLabels,
            'matrix' => $matrix,
            'maxCell' => $maxCell,
            'generated' => $generated,
            'succeeded' => $succeeded,
            'conversionRate' => $generated > 0 ? round(($succeeded / $generated) * 100, 2) : 0,
        ];
    }

    private function analyticsLatency(array $filters): array
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $diffExpr = $isSqlite
            ? '(julianday(topup_requests.succeeded_at) - julianday(topup_requests.submitted_at)) * 86400'
            : 'TIMESTAMPDIFF(SECOND, topup_requests.submitted_at, topup_requests.succeeded_at)';
        $dateExpr = $isSqlite ? "date(topup_requests.submitted_at, '+7 hours')" : "DATE(CONVERT_TZ(topup_requests.submitted_at, '+00:00', '+07:00'))";

        $merchantIds = $this->maScopedMerchantIds();

        $rows = TopupRequest::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('topup_requests.merchant_id', $merchantIds))
            ->where('topup_requests.status', 'success')
            ->whereNotNull('topup_requests.succeeded_at')
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw("{$dateExpr} as d, AVG({$diffExpr}) as avg_seconds")
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        return [
            'labels' => $rows->pluck('d')->values(),
            'avgSeconds' => $rows->pluck('avg_seconds')->map(fn ($v) => round((float) $v, 1))->values(),
            'overallAvgSeconds' => round((float) $rows->avg('avg_seconds'), 1),
        ];
    }

    private function analyticsChannelReliability(array $filters): array
    {
        $merchantIds = $this->maScopedMerchantIds();

        // "channel" is a generated column (see the add_channel_column_to_topup_requests_table
        // migration) backed by an index, instead of grouping by a raw JSON_EXTRACT
        // expression computed per row - that alone took ~7s at this table's row count.
        $rows = TopupRequest::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('topup_requests.merchant_id', $merchantIds))
            ->whereIn('topup_requests.status', ['success', 'failed', 'expired', 'rejected'])
            ->whereNotNull('topup_requests.channel')
            // JSON_UNQUOTE on a JSON null value (payload has issuer_name/bank_name
            // explicitly set to null, not absent) yields the literal string "null",
            // not a real NULL - whereNotNull() alone doesn't catch that.
            ->where('topup_requests.channel', '!=', 'null')
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('topup_requests.channel as channel')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN topup_requests.status = 'success' THEN 1 ELSE 0 END) as success_count")
            ->groupBy('topup_requests.channel')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        return [
            'rows' => $rows->map(fn ($r) => [
                'channel' => $r->channel,
                'total' => (int) $r->total,
                'successCount' => (int) $r->success_count,
                'successRate' => $r->total > 0 ? round(($r->success_count / $r->total) * 100, 2) : 0,
            ]),
        ];
    }

    private function analyticsOperations(array $filters): array
    {
        $merchantIds = $this->maScopedMerchantIds();

        $rows = TopupRequest::query()
            ->leftJoin('support_tickets', function ($join) {
                $join->on('support_tickets.topup_request_id', '=', 'topup_requests.id')
                    ->whereIn('support_tickets.center_status', ['issue_bank', 'issue_switching']);
            })
            ->whereIn('topup_requests.status', ['failed', 'expired', 'rejected'])
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('topup_requests.merchant_id', $merchantIds))
            ->when($filters['from'], fn ($query) => $query->where('topup_requests.submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('topup_requests.submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('topup_requests.merchant_id as merchant_id')
            ->selectRaw('COUNT(DISTINCT topup_requests.id) as failed_count')
            ->selectRaw('COUNT(DISTINCT support_tickets.id) as with_ticket_count')
            ->groupBy('topup_requests.merchant_id')
            ->having('failed_count', '>', 0)
            ->orderByDesc('failed_count')
            ->limit(50)
            ->get();

        $merchantNames = Merchant::query()->whereIn('id', $rows->pluck('merchant_id'))->pluck('name', 'id');
        $rows->each(function ($row) use ($merchantNames): void {
            $row->merchant_name = $merchantNames->get($row->merchant_id) ?? '-';
        });

        return [
            'perMerchant' => $rows,
            'totalFailed' => (int) $rows->sum('failed_count'),
            'totalWithTicket' => (int) $rows->sum('with_ticket_count'),
        ];
    }

    private function analyticsOutlierDetection(): array
    {
        $merchantIds = $this->merchants($this->blankFilters())->pluck('id');
        $todayStart = now('Asia/Jakarta')->startOfDay()->utc();
        $todayEnd = now('Asia/Jakarta')->endOfDay()->utc();
        $sevenDaysAgo = now('Asia/Jakarta')->subDays(7)->startOfDay()->utc();
        $yesterday = now('Asia/Jakarta')->subDay()->endOfDay()->utc();

        $todayVolumes = TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'success')
            ->whereBetween('submitted_at', [$todayStart, $todayEnd])
            ->selectRaw('merchant_id, COUNT(*) as trx')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $avgVolumes = TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'success')
            ->whereBetween('submitted_at', [$sevenDaysAgo, $yesterday])
            ->selectRaw('merchant_id, COUNT(*) / 7 as avg_trx')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $merchants = Merchant::query()->whereIn('id', $merchantIds)->get(['id', 'name'])->keyBy('id');

        $rows = $merchantIds->map(function ($id) use ($todayVolumes, $avgVolumes, $merchants) {
            $todayTrx = (int) ($todayVolumes->get($id)->trx ?? 0);
            $avgTrx = (float) ($avgVolumes->get($id)->avg_trx ?? 0);
            $deviation = $avgTrx > 0 ? round((($todayTrx - $avgTrx) / $avgTrx) * 100, 1) : null;

            return [
                'merchant_name' => $merchants->get($id)?->name ?? '-',
                'today_trx' => $todayTrx,
                'avg_trx' => round($avgTrx, 1),
                'deviation_percent' => $deviation,
                'is_outlier' => $avgTrx >= 5 && $deviation !== null && abs($deviation) >= 50,
            ];
        })->filter(fn ($r) => $r['avg_trx'] > 0)->sortBy('deviation_percent')->values();

        return ['rows' => $rows];
    }

    private function analyticsTicketSla(array $filters): array
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $hoursExpr = $isSqlite
            ? '(julianday(support_tickets.closed_at) - julianday(support_tickets.created_at)) * 24'
            : 'TIMESTAMPDIFF(SECOND, support_tickets.created_at, support_tickets.closed_at) / 3600';

        $merchantIds = $this->maScopedMerchantIds();

        $base = SupportTicket::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('support_tickets.merchant_id', $merchantIds))
            ->whereNotNull('support_tickets.closed_at')
            ->when($filters['from'], fn ($query) => $query->where('support_tickets.created_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('support_tickets.created_at', '<=', $this->rangeEnd($filters['to'])));

        $hours = (clone $base)->selectRaw("{$hoursExpr} as hours")->get()->pluck('hours')->map(fn ($v) => (float) $v);
        $totalResolved = $hours->count();
        $withinSlaCount = $hours->filter(fn ($h) => $h <= 24)->count();

        $perCs = (clone $base)
            ->whereNotNull('support_tickets.center_updated_by_user_id')
            ->join('users', 'users.id', '=', 'support_tickets.center_updated_by_user_id')
            ->selectRaw('users.id as cs_id, users.name as cs_name')
            ->selectRaw("COUNT(*) as total, AVG({$hoursExpr}) as avg_hours")
            ->selectRaw("SUM(CASE WHEN ({$hoursExpr}) <= 24 THEN 1 ELSE 0 END) as within_sla_count")
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->get();

        return [
            'totalResolved' => $totalResolved,
            'withinSlaCount' => $withinSlaCount,
            'withinSlaPercent' => $totalResolved > 0 ? round(($withinSlaCount / $totalResolved) * 100, 2) : 0,
            'avgResolutionHours' => round((float) $hours->avg(), 1),
            'perCs' => $perCs,
        ];
    }

    private function analyticsAccountActivity(): array
    {
        $maId = $this->currentMaId();
        $merchantIds = $this->merchants($this->blankFilters())->pluck('id');

        $users = User::query()
            ->where(fn ($query) => $query->where('ma_user_id', $maId)->orWhereIn('merchant_id', $merchantIds))
            ->whereIn('role', ['agent', 'cs_agent', 'admin', 'readonly_admin', 'cs', 'readonly_cs', 'finance'])
            ->get(['id', 'name', 'role']);

        $lastLogins = DB::table('audit_logs')
            ->where('action', 'auth.login_success')
            ->whereIn('actor_user_id', $users->pluck('id'))
            ->selectRaw('actor_user_id, MAX(created_at) as last_login')
            ->groupBy('actor_user_id')
            ->get()
            ->keyBy('actor_user_id');

        $rows = $users->map(function ($user) use ($lastLogins) {
            $last = $lastLogins->get($user->id)?->last_login;

            return [
                'name' => $user->name,
                'role' => $user->role,
                'last_login' => $last,
                'days_since_login' => $last ? (int) floor(CarbonImmutable::parse($last)->diffInDays(now())) : null,
            ];
        })->sortByDesc(fn ($r) => $r['days_since_login'] ?? 99999)->values();

        return ['rows' => $rows];
    }

    private function analyticsProvisioningFailures(): array
    {
        $failed = $this->merchants($this->blankFilters())
            ->where('merchants.provisioning_status', 'failed')
            ->get();

        $topErrors = $failed
            ->groupBy(fn ($m) => $m->provisioning_error ?: 'Error tidak tercatat')
            ->map(fn ($group, $error) => ['error' => $error, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values();

        return [
            'rows' => $failed->map(fn ($m) => [
                'merchant_name' => $m->name,
                'error' => $m->provisioning_error ?: '-',
                'attempts' => (int) $m->provisioning_attempts,
                'last_attempt' => optional($m->updated_at)->toDateTimeString(),
            ])->values(),
            'topErrors' => $topErrors,
            'totalFailed' => $failed->count(),
        ];
    }

    private function analyticsHealthScore(): array
    {
        $merchantIds = $this->merchants($this->blankFilters())->pluck('id');
        $from = now('Asia/Jakarta')->subDays(29)->startOfDay()->utc();

        $txStats = TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('submitted_at', '>=', $from)
            ->selectRaw('merchant_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'expired', 'rejected') THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND is_processed = 0 THEN 1 ELSE 0 END) as backlog")
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        // SupportTicket is deliberately excluded here: it's auto-created per failed/expired
        // transaction and effectively never moves to status=success in bulk (18k+ rows, 0
        // ever resolved), so it just re-counts the same signal failedRate already captures.
        // MerchantTicket (manually opened by a store/agent) is the genuine "flagged issue" signal.
        $openMerchantTickets = MerchantTicket::query()
            ->whereIn('merchant_id', $merchantIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->selectRaw('merchant_id, COUNT(*) as cnt')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $merchants = Merchant::query()->whereIn('id', $merchantIds)->get(['id', 'name'])->keyBy('id');

        $rows = $merchantIds->map(function ($id) use ($txStats, $openMerchantTickets, $merchants) {
            $stat = $txStats->get($id);
            $total = (int) ($stat->total ?? 0);
            $success = (int) ($stat->success ?? 0);
            $failedRate = $total > 0 ? round(((int) ($stat->failed ?? 0) / $total) * 100, 1) : 0;
            $backlogCount = (int) ($stat->backlog ?? 0);
            $backlogRate = $success > 0 ? round(($backlogCount / $success) * 100, 1) : 0;
            $openTickets = (int) ($openMerchantTickets->get($id)->cnt ?? 0);

            $score = 100 - min(40, $failedRate) - min(30, $backlogRate) - min(30, $openTickets * 5);

            return [
                'merchant_name' => $merchants->get($id)?->name ?? '-',
                'score' => (int) round(max(0, $score)),
                'failedRate' => $failedRate,
                'backlogCount' => $backlogCount,
                'openTickets' => $openTickets,
            ];
        })->sortBy('score')->values();

        return ['rows' => $rows];
    }

    /**
     * Shared per-merchant success-only aggregate for the Overview page's period
     * filters. storeRanking()/storeSummaries()/agentRanking() all group the exact
     * same whereIn(merchant_id)+status=success+from/to slice of topup_requests -
     * computing it once here and passing it in avoids scanning the same ~100k+
     * row window 3 separate times per page load.
     */
    private function merchantSuccessTotals(array $filters, $merchantIds)
    {
        return TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'success')
            ->when($filters['from'], fn ($query) => $query->where('submitted_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('submitted_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('merchant_id, COUNT(*) as trx_total')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume_success')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as settlement')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');
    }

    /**
     * Per-WIB-day breakdown for the Overview hero/quick-stat tiles: success
     * volume+fee, pending, and "at risk" (failed/expired/rejected - money that
     * won't be collected) amounts. Powers both the today-vs-yesterday deltas
     * and the trailing sparklines from a single query, zero-filled for gap
     * days so the caller can always index the last N days safely.
     */
    private function dailyMetricSeries($merchantIds, int $days = 14): array
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $dateExpr = $isSqlite ? "date(submitted_at, '+7 hours')" : "DATE(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";
        $since = now('Asia/Jakarta')->subDays($days - 1)->startOfDay()->utc();

        $rows = TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('submitted_at', '>=', $since)
            ->selectRaw("{$dateExpr} as d")
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as trx_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN fee_amount ELSE 0 END), 0) as fee")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as trx_pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->selectRaw("SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as trx_risk")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN amount ELSE 0 END), 0) as risk_amount")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $withdrawalDateExpr = $isSqlite ? "date(gateway_created_at, '+7 hours')" : "DATE(CONVERT_TZ(gateway_created_at, '+00:00', '+07:00'))";
        $withdrawalRows = MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'COMPLETED')
            ->where('gateway_created_at', '>=', $since)
            ->selectRaw("{$withdrawalDateExpr} as d")
            ->selectRaw('COALESCE(SUM(amount), 0) as withdrawal')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now('Asia/Jakarta')->subDays($i)->toDateString();
            $row = $rows->get($date);
            $trxSuccess = (int) ($row->trx_success ?? 0);
            $trxPending = (int) ($row->trx_pending ?? 0);
            $trxRisk = (int) ($row->trx_risk ?? 0);
            $attempted = $trxSuccess + $trxPending + $trxRisk;
            $series[] = [
                'date' => $date,
                'trx_success' => $trxSuccess,
                'volume_success' => (int) ($row->volume_success ?? 0),
                'fee' => (int) ($row->fee ?? 0),
                'trx_pending' => $trxPending,
                'pending_amount' => (int) ($row->pending_amount ?? 0),
                'risk_amount' => (int) ($row->risk_amount ?? 0),
                'success_rate' => $attempted > 0 ? round($trxSuccess / $attempted * 100, 2) : 0,
                'withdrawal' => (int) ($withdrawalRows->get($date)->withdrawal ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Today vs yesterday, 24 hourly buckets each (WIB) - powers the Overview
     * "Transaction Flow" chart's default hourly view (1H/6H/24H are all just
     * PHP-side slices of this same 24-bucket array, no extra queries).
     */
    private function overviewHourlyFlow($merchantIds): array
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $dateExpr = $isSqlite ? "date(submitted_at, '+7 hours')" : "DATE(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";
        $hourExpr = $isSqlite ? "CAST(strftime('%H', submitted_at, '+7 hours') AS INTEGER)" : "HOUR(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";

        $todayStart = now('Asia/Jakarta')->startOfDay();
        $yesterdayStart = $todayStart->copy()->subDay();
        $today = $todayStart->toDateString();
        $yesterday = $yesterdayStart->toDateString();

        $rows = TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('submitted_at', '>=', $yesterdayStart->utc())
            ->where('submitted_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw("{$dateExpr} as d, {$hourExpr} as h")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN fee_amount ELSE 0 END), 0) as fee")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_trx")
            ->groupBy('d', 'h')
            ->get()
            ->groupBy('d');

        $withdrawalDateExpr = $isSqlite ? "date(gateway_created_at, '+7 hours')" : "DATE(CONVERT_TZ(gateway_created_at, '+00:00', '+07:00'))";
        $withdrawalHourExpr = $isSqlite ? "CAST(strftime('%H', gateway_created_at, '+7 hours') AS INTEGER)" : "HOUR(CONVERT_TZ(gateway_created_at, '+00:00', '+07:00'))";
        $withdrawalRows = MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'COMPLETED')
            ->where('gateway_created_at', '>=', $yesterdayStart->utc())
            ->where('gateway_created_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw("{$withdrawalDateExpr} as d, {$withdrawalHourExpr} as h")
            ->selectRaw('COALESCE(SUM(amount), 0) as withdrawal')
            ->groupBy('d', 'h')
            ->get()
            ->groupBy('d');

        $extract = function ($dateKey, string $field) use ($rows) {
            $byHour = ($rows->get($dateKey) ?? collect())->keyBy('h');
            $out = [];
            for ($h = 0; $h < 24; $h++) {
                $out[] = (int) ($byHour->get($h)->{$field} ?? 0);
            }

            return $out;
        };

        $extractWithdrawal = function ($dateKey) use ($withdrawalRows) {
            $byHour = ($withdrawalRows->get($dateKey) ?? collect())->keyBy('h');
            $out = [];
            for ($h = 0; $h < 24; $h++) {
                $out[] = (int) ($byHour->get($h)->withdrawal ?? 0);
            }

            return $out;
        };

        $labels = [];
        for ($h = 0; $h < 24; $h++) {
            $labels[] = sprintf('%02d:00', $h);
        }

        return [
            'labels' => $labels,
            'todayVolume' => $extract($today, 'volume'),
            'yesterdayVolume' => $extract($yesterday, 'volume'),
            'todayFee' => $extract($today, 'fee'),
            'yesterdayFee' => $extract($yesterday, 'fee'),
            'todayPendingAmount' => $extract($today, 'pending_amount'),
            'yesterdayPendingAmount' => $extract($yesterday, 'pending_amount'),
            'todayPendingTrx' => $extract($today, 'pending_trx'),
            'yesterdayPendingTrx' => $extract($yesterday, 'pending_trx'),
            'todayWithdrawal' => $extractWithdrawal($today),
            'yesterdayWithdrawal' => $extractWithdrawal($yesterday),
            'currentHour' => (int) now('Asia/Jakarta')->format('G'),
        ];
    }

    /**
     * Derives today-vs-yesterday deltas and 7-day sparklines for the Overview
     * quick-stat tiles from the shared dailyMetricSeries() output - no extra
     * queries, just reshaping the last 14 days already fetched.
     */
    private function overviewQuickStats(array $dailySeries): array
    {
        $today = end($dailySeries);
        $yesterday = $dailySeries[count($dailySeries) - 2] ?? null;
        $pctChange = fn ($cur, $prev) => $prev > 0 ? round((($cur - $prev) / $prev) * 100, 1) : ($cur > 0 ? null : 0);
        $spark = fn (string $field) => collect($dailySeries)->pluck($field)->slice(-7)->values()->all();

        $metrics = [
            'revenue_today' => 'fee',
            'successful_volume' => 'volume_success',
            'success_rate' => 'success_rate',
            'pending' => 'pending_amount',
            'at_risk' => 'risk_amount',
        ];

        return collect($metrics)->map(fn ($field) => [
            'value' => $today[$field],
            'change' => $pctChange($today[$field], $yesterday[$field] ?? 0),
            'spark' => $spark($field),
        ])->all();
    }

    /**
     * Today's (WIB, 00:00 - now) disbursement totals for the Overview page's
     * "Row 2" panel - reshapes the same dailyMetricSeries() bucket already
     * fetched for the quick-stat tiles, no extra queries.
     */
    private function overviewDisbursement(array $dailySeries): array
    {
        $today = end($dailySeries);

        return [
            'total' => $today['volume_success'],
            'total_trx' => $today['trx_success'],
            'pending' => $today['pending_amount'],
            'pending_trx' => $today['trx_pending'],
        ];
    }

    /**
     * Top 10 merchants by today's volume, for the Overview "Top Ten Merchant
     * Daily" panel - always today-scoped regardless of the page's own period
     * filter, so it needs its own totals query rather than reusing $dataFilters.
     */
    private function topMerchants(array $filters, $merchants = null, $totals = null)
    {
        $merchants ??= $this->merchants($filters)->get();
        $totals ??= $this->merchantSuccessTotals($filters, $merchants->pluck('id'));

        return $totals
            ->map(function ($row) use ($merchants) {
                $row = (array) $row;

                return (object) [
                    'merchant_id' => $row['merchant_id'],
                    'trx' => $row['trx_total'],
                    'volume' => $row['volume_success'],
                    'withdrawal' => 0,
                    'merchant' => $merchants->firstWhere('id', $row['merchant_id']),
                ];
            })
            ->sortByDesc('volume')
            ->take(10)
            ->values();
    }

    /**
     * Today's (WIB) completed withdrawal amount per merchant, from the local
     * merchant_withdrawals table (kept fresh by the gateway:sync-withdrawals
     * schedule) - not a live Hilogate call, so this stays fast and unaffected
     * by a merchant's gateway credential being temporarily broken.
     */
    private function withdrawalTotalsToday($merchants): array
    {
        $todayStart = now('Asia/Jakarta')->startOfDay();
        $merchantIds = $merchants->filter()->pluck('id');

        return MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'COMPLETED')
            ->where('gateway_created_at', '>=', $todayStart->utc())
            ->where('gateway_created_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw('merchant_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('merchant_id')
            ->pluck('total', 'merchant_id')
            ->all();
    }

    /**
     * Top 10 QRIS payment sources (bank/channel) by today's successful TRX
     * count and amount, for the Overview "QRIS Source" panel - replaces the
     * old per-transaction live ticker with an aggregated daily ranking.
     */
    private function topPaymentSources($merchantIds): \Illuminate\Support\Collection
    {
        $todayStart = now('Asia/Jakarta')->startOfDay();

        return TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'success')
            ->whereNotNull('channel')
            ->where('channel', '!=', '')
            ->where('submitted_at', '>=', $todayStart->utc())
            ->where('submitted_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw('channel, COUNT(*) as trx_total')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('channel')
            ->orderByDesc('volume')
            ->limit(10)
            ->get();
    }

    /**
     * Top 10 banks by withdrawal amount, for the Overview "Withdrawal by Bank"
     * panels - completed is today-scoped (matches the rest of this page's
     * daily framing); pending has no date filter since a stuck withdrawal
     * from days ago is exactly what this panel exists to surface.
     */
    private function withdrawalsByBank($merchantIds, string $status): \Illuminate\Support\Collection
    {
        $query = MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', $status)
            ->whereNotNull('bank_name')
            ->where('bank_name', '!=', '');

        if ($status === 'COMPLETED') {
            $todayStart = now('Asia/Jakarta')->startOfDay();
            $query->where('gateway_created_at', '>=', $todayStart->utc())
                ->where('gateway_created_at', '<', $todayStart->copy()->addDay()->utc());
        }

        return $query->selectRaw('bank_name, COUNT(*) as trx_total')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('bank_name')
            ->orderByDesc('volume')
            ->limit(10)
            ->get();
    }

    private function ticketQuery(array $filters)
    {
        $maId = $this->currentMaId();

        return SupportTicket::query()
            ->when($maId, fn ($query) => $query->whereIn('merchant_id', Merchant::query()->whereRelation('agent', 'ma_user_id', $maId)->pluck('id')))
            ->when($filters['from'], fn ($query) => $query->where('created_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('created_at', '<=', $this->rangeEnd($filters['to'])));
    }

    /**
     * Returned in UTC (not Asia/Jakarta) because every call site binds this
     * straight into a `where('utc_datetime_column', ...)` clause. Laravel's
     * query grammar formats a bound DateTimeInterface with ->format() as-is -
     * it does NOT convert to UTC first - so a WIB-tagged Carbon here would get
     * its WIB wall-clock digits bound literally against a UTC-stored column,
     * silently shifting every date-range filter 7 hours later than intended.
     * If you need the plain WIB calendar-date string instead (e.g. to compare
     * against a DATE column like settlement_date), use wibDate()/wibDateEnd()
     * instead of calling ->toDateString() on this.
     */
    private function rangeStart(string $value): CarbonImmutable
    {
        $parsed = CarbonImmutable::parse($value, 'Asia/Jakarta');

        return (str_contains($value, ':') ? $parsed : $parsed->startOfDay())->utc();
    }

    private function rangeEnd(string $value): CarbonImmutable
    {
        $parsed = CarbonImmutable::parse($value, 'Asia/Jakarta');

        return (str_contains($value, ':') ? $parsed : $parsed->endOfDay())->utc();
    }

    /**
     * Plain WIB calendar-date string, for comparing against a DATE column
     * (e.g. settlement_date) that has no time-of-day or timezone of its own -
     * unlike rangeStart()/rangeEnd(), this must NOT be UTC-converted first, or
     * dates near midnight WIB would shift to the wrong calendar day.
     */
    private function wibDate(string $value): string
    {
        return CarbonImmutable::parse($value, 'Asia/Jakarta')->toDateString();
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
