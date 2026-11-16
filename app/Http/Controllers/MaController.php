<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\CommissionConfig;
use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Models\MerchantCommissionReport;
use App\Models\MerchantDailyMetric;
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
use Barryvdh\DomPDF\Facade\Pdf;
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

        if ($page === 'approval') {
            app(\App\Services\NotificationReadService::class)->markSeen(auth()->user(), 'ma-approvals');
            Cache::forget('ma:sidebar-badges:'.auth()->id());
        }

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
        $adminUsersByMerchant = collect();
        if ($page === 'stores') {
            $activity = $this->cachedAnalytics('stores-activity', $filters, fn () => $this->storeActivityByMerchant($merchants->pluck('id')));
            $merchants->each(function ($m) use ($activity) {
                $row = $activity[$m->id] ?? null;
                $m->activity_trx_total = (int) ($row['trx_total'] ?? 0);
                $m->activity_volume_success = (int) ($row['volume_success'] ?? 0);
                $m->activity_last_transaction_at = ! empty($row['last_transaction_at']) ? CarbonImmutable::parse($row['last_transaction_at']) : null;
            });

            $adminUsersByMerchant = User::query()
                ->where('role', 'admin')
                ->where('is_hidden', false)
                ->whereIn('merchant_id', $merchants->pluck('id'))
                ->get()
                ->keyBy('merchant_id');
        }

        $overviewMerchants = $page === 'overview' ? $this->merchants($dataFilters)->get() : null;
        // This query scans most of topup_requests (60 days, needed for the
        // Transaction Flow chart's Month-vs-previous-Month comparison) - on
        // a large merchant portfolio it's the single most expensive query on
        // this page (full scan, not index-fixable: see the commit message
        // for the EXPLAIN that ruled out FORCE INDEX). A longer TTL than the
        // 180s default cuts how often that cost gets paid; a real fix needs
        // a pre-aggregated daily-stats table, which is a bigger project.
        $overviewDailySeries = $page === 'overview'
            ? $this->cachedAnalytics('overview-daily-series', $dataFilters, fn () => $this->dailyMetricSeries($overviewMerchants->pluck('id'), 60), 300)
            : null;
        $overviewQuickStats = $overviewDailySeries ? $this->overviewQuickStats($overviewDailySeries) : null;

        if ($page === 'overview') {
            $overviewTopMerchants = $this->topMerchants($this->todayFilters(), $overviewMerchants, collect($this->cachedAnalytics('overview-top-merchants-today', $this->todayFilters(), fn () => $this->merchantSuccessTotals($this->todayFilters(), $overviewMerchants->pluck('id')))));
            $withdrawals = collect($this->cachedAnalytics('overview-withdrawals-today', $this->todayFilters(), fn () => $this->withdrawalTotalsToday($overviewTopMerchants->pluck('merchant'))));
            $overviewTopMerchants->each(function ($row) use ($withdrawals) {
                $row->withdrawal = $withdrawals[$row->merchant_id] ?? 0;
            });
            $topPaymentSources = collect($this->cachedAnalytics('overview-top-payment-sources', $this->todayFilters(), fn () => $this->topPaymentSources($overviewMerchants->pluck('id'))));
        } else {
            $overviewTopMerchants = collect();
            $topPaymentSources = collect();
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
            'approvalCounts' => $page === 'approval' ? $this->approvalCounts() : [],
            'transactions' => $page === 'report' && $filters['report_view'] !== 'withdrawal' ? $this->transactions($dataFilters)->simplePaginate(25)->withQueryString() : null,
            'selectedStoreStats' => $page === 'report' && $filters['report_view'] !== 'withdrawal' ? $this->cachedAnalytics('report-store-stats', $dataFilters, fn () => $this->selectedStoreStats($dataFilters), 15) : null,
            'withdrawals' => $page === 'report' && $filters['report_view'] === 'withdrawal' ? $this->withdrawals($dataFilters)->simplePaginate(25)->withQueryString() : null,
            'selectedStoreWithdrawalStats' => $page === 'report' && $filters['report_view'] === 'withdrawal' ? $this->cachedAnalytics('report-store-withdrawal-stats', $dataFilters, fn () => $this->selectedStoreWithdrawalStats($dataFilters), 15) : null,
            'summary' => match ($page) {
                'overview' => $this->cachedAnalytics('overview-summary', $dataFilters, fn () => $this->summary($dataFilters)),
                'fee' => $this->summary($dataFilters),
                default => [],
            },
            'reportAgents' => $page === 'report'
                ? ($filters['report_view'] === 'withdrawal'
                    ? $this->cachedAnalytics('report-agents-withdrawal', $dataFilters, fn () => $this->reportAgentsWithdrawal($dataFilters), 15)
                    : $this->cachedAnalytics('report-agents', $dataFilters, fn () => $this->reportAgents($dataFilters), 15))
                : collect(),
            'topMerchants' => $overviewTopMerchants,
            'topPaymentSources' => $topPaymentSources,
            'withdrawalsCompletedByBank' => $page === 'overview'
                ? collect($this->cachedAnalytics('overview-withdrawals-completed-by-bank', $this->todayFilters(), fn () => $this->withdrawalsByBank($overviewMerchants->pluck('id'), 'COMPLETED')))
                : collect(),
            'withdrawalsPendingByBank' => $page === 'overview'
                ? collect($this->cachedAnalytics('overview-withdrawals-pending-by-bank', $this->todayFilters(), fn () => $this->withdrawalsByBank($overviewMerchants->pluck('id'), 'PENDING')))
                : collect(),
            'overviewDailySeries' => $overviewDailySeries ?? [],
            'overviewHourlyFlow' => $page === 'overview'
                ? array_merge(
                    $this->cachedAnalytics('overview-hourly-flow', $dataFilters, fn () => $this->overviewHourlyFlow(
                        $overviewMerchants->pluck('id'),
                        $topPaymentSources->take(10)->pluck('channel')->all(),
                        $overviewTopMerchants->pluck('merchant_id')->all(),
                    )),
                    // Longer TTL (10 min vs the default 3 min) - this is the
                    // most expensive query on the page (30-day GROUP BY per
                    // channel/merchant) and users rarely touch the 7D/Month
                    // toggle, so there's no need to keep it as fresh as the
                    // hourly data above.
                    $this->cachedAnalytics('overview-daily-breakdown', $dataFilters, fn () => $this->overviewDailyBreakdown(
                        $overviewMerchants->pluck('id'),
                        $topPaymentSources->take(10)->pluck('channel')->all(),
                        $overviewTopMerchants->pluck('merchant_id')->all(),
                    ), 600)
                )
                : [],
            'overviewQuickStats' => $overviewQuickStats ?? [],
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
            'groupedMerchantsForAnalytics' => in_array($page, ['analytics', 'report'], true)
                ? $this->cachedAnalytics('grouped-merchants', [], fn () => $this->groupedMerchantsForAnalytics())
                : collect(),
            'adminUsersByMerchant' => $adminUsersByMerchant,
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
                'analyticsAccountActivity' => $this->cachedAnalytics('account-activity', [], fn () => $this->analyticsAccountActivity()),
                'analyticsProvisioningFailures' => $this->cachedAnalytics('provisioning-failures', [], fn () => $this->analyticsProvisioningFailures()),
                'analyticsConnectivityIssues' => $this->cachedAnalytics('connectivity-issues', [], fn () => $this->analyticsConnectivityIssues()),
            ]);
        }

        return view('paygrid.partials.ma-analytics-operations', [
            'periodLabel' => $this->periodLabel($dataFilters),
            'analyticsOperations' => $this->cachedAnalytics('operations', $dataFilters, fn () => $this->analyticsOperations($dataFilters)),
            'analyticsOutlierDetection' => $this->cachedAnalytics('outlier-detection', [], fn () => $this->analyticsOutlierDetection()),
            'analyticsTicketSla' => $this->cachedAnalytics('ticket-sla', $dataFilters, fn () => $this->analyticsTicketSla($dataFilters)),
            'analyticsTicketVolume' => $this->cachedAnalytics('ticket-volume', $dataFilters, fn () => $this->analyticsTicketVolume($dataFilters)),
            'analyticsHealthScore' => $this->cachedAnalytics('health-score', [], fn () => $this->analyticsHealthScore()),
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->periodFilters($this->filters());

        if ($filters['report_view'] === 'withdrawal') {
            $rows = $this->withdrawals($filters)->limit(5000)->get();
            $csv = "Waktu,Selesai,Toko,Agen,Bank,Nama Akun,No Rekening,Status,Amount,Net,Fee\n";
            foreach ($rows as $row) {
                $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', [
                    $row->gateway_created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    $row->gateway_completed_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                    $row->merchant?->name,
                    $row->merchant?->agent?->name,
                    $row->bank_name,
                    $row->account_name,
                    $row->account_number,
                    $row->status,
                    $row->amount,
                    $row->net_amount,
                    $row->fee,
                ]))."\n";
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="ma-report-withdrawal.csv"',
            ]);
        }

        $rows = $this->transactions($filters)->limit(5000)->get();
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

    public function feeSplitExport(FeeMenuCatalog $feeMenus): Response
    {
        $filters = $this->filters();
        $dataFilters = $this->periodFilters($filters);
        $merchants = $this->merchants($filters)->get();
        $feeAmounts = $this->feeAmountsByMerchant($dataFilters);
        $feeMenuOptions = $feeMenus->optionsFor('merchant');

        $csv = "Toko,Menu Fee,MDR Toko (%),Based Fee Agent (%),Based Fee MA (%),Volume Trx,Margin Agent (%),Margin MA (%),Estimasi Fee Agen (Rp),Estimasi Fee MA (Rp),Status\n";
        foreach ($merchants as $m) {
            $amounts = $feeAmounts->get($m->id);
            $basedFeeAgent = (float) $m->agent_fee_percent;
            $basedFeeMa = (float) $m->ma_fee_percent;
            $mdrToko = (float) $m->merchant_mdr_percent;
            $marginAgent = $mdrToko - $basedFeeAgent;
            $marginMa = $basedFeeAgent - $basedFeeMa;
            $isValidFloor = $marginAgent >= -0.001 && $marginMa >= -0.001;
            $menuLabel = $m->fee_menu ? ($feeMenuOptions[$m->fee_menu]['label'] ?? $m->fee_menu) : '-';

            $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', [
                $m->name,
                $menuLabel,
                number_format($mdrToko, 3, '.', ''),
                number_format($basedFeeAgent, 3, '.', ''),
                number_format($basedFeeMa, 3, '.', ''),
                (int) ($amounts->volume ?? 0),
                number_format($marginAgent, 3, '.', ''),
                number_format($marginMa, 3, '.', ''),
                (int) round((float) ($amounts->agent_fee ?? 0)),
                (int) round((float) ($amounts->ma_fee ?? 0)),
                $isValidFloor ? 'Valid' : 'Gak Sinkron',
            ]))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ma-fee-split.csv"',
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

        return back()->with('status', 'Agen lokal berhasil dibuat. Kode login: '.$agent->code.'. Password: '.$password.'. Belum dikirim ke HG. Akun CS Agent — Username: '.$csUser->username.', Password: '.$password.'.')->with('new_agent_id', $agent->id);
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
        if ($request->user()->is_demo) {
            return back()->with('status', 'Toko "'.$request->input('name', 'Demo').'" berhasil dibuat. (Mode demo — data tidak benar-benar disimpan.)');
        }

        $typeCategory = $feeMenus->typeCategory((string) $request->input('merchant_type'));
        $rates = $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'merchant');
        $request->merge(['fee_menu_rates' => $rates]);
        $agentRates = (array) (Agent::find($request->input('agent_id'))?->fee_menu_rates ?? []);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['nullable', 'string', 'max:80'],
            'engine_name' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['required', 'exists:agents,id'],
            'pic_email' => ['nullable', 'string', 'max:255'],
            'pic_telegram' => ['nullable', 'string', 'max:255'],
            'pic_whatsapp' => ['nullable', 'string', 'max:255'],
            'admin_email' => ['required', 'array', 'min:1'],
            'admin_email.*' => ['required', 'email', 'max:160', 'distinct', 'unique:users,email'],
            'cs_email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'gateway' => ['required', 'in:hilogate,artageto,alpha,kingspay,magnetopay'],
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
            'pic_whatsapp' => $data['pic_whatsapp'] ?? null,
            'cs_email' => $data['cs_email'] ?? null,
            'fee_menu' => $data['fee_menu'],
            'fee_menu_rates' => $data['fee_menu_rates'],
            'settlement_method' => $data['settlement_method'],
            ...$feeSync->snapshotFor($agent, $data['fee_menu'], $data['merchant_mdr_percent']),
            'onboarding_payload' => $data + ['api_ip_whitelist' => $data['api_ip_whitelist'] ?: '15.232.137.74'],
            'approved_at' => now(),
        ]);
        $admins = collect($data['admin_email'])->map(fn (string $email) => User::query()->create([
            'name' => str($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
            'email' => $email,
            'role' => 'admin',
            'merchant_id' => $merchant->id,
            'password' => Hash::make(config('paygrid.demo_password')),
            'plain_password' => config('paygrid.demo_password'),
        ]));
        $audit->record('ma.merchant_created', $merchant, null, $merchant->only(['slug', 'name', 'agent_id', 'gateway', 'merchant_type']));
        $admins->each(fn ($admin) => $audit->record('ma.merchant_admin_created', $admin, null, $admin->only(['email', 'role', 'merchant_id'])));

        return back()->with('status', 'Toko berhasil dibuat. Admin default: '.$admins->pluck('email')->implode(', ').' / '.config('paygrid.demo_password').'.');
    }

    public function createStoreMagnetopayForm(FeeMenuCatalog $feeMenus): View
    {
        return view('paygrid.ma-create-store-magnetopay', [
            'roleLabel' => request()->user()?->role === 'magneto_ops' ? 'Magneto Ops' : 'MA',
            'menus' => app(\App\Services\Navigation\MenuBuilder::class)->ma(),
            'active' => 'create-store-magnetopay',
            'feeMenus' => $feeMenus,
            'allAgents' => $this->agents($this->blankFilters())->get(),
            'onboardingLinks' => AgentOnboardingLink::query()
                ->where('kind', 'magnetopay')
                ->when($this->currentMaId() !== null, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $this->currentMaId()))
                ->with('agent', 'registration')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function storeMagnetopayMerchant(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'agent_id' => ['required', 'exists:agents,id'],
            'admin_email' => ['required', 'array', 'min:1'],
            'admin_email.*' => ['required', 'email', 'max:160', 'distinct', 'unique:users,email'],
            'merchant_id' => ['nullable', 'string', 'max:160'],
            'merchant_key' => ['nullable', 'string', 'max:255'],
            'merchant_group_name' => ['nullable', 'string', 'max:160'],
            'transaction_callback_url' => ['nullable', 'url', 'max:255'],
            'withdrawal_callback_url' => ['nullable', 'url', 'max:255'],
            'va_callback_url' => ['nullable', 'url', 'max:255'],
            'qris_settlement_type' => ['nullable', 'string', 'max:80'],
            'va_settlement_type' => ['nullable', 'string', 'max:80'],
            'ip_whitelist_enabled' => ['nullable', 'boolean'],
            'dashboard_ip_whitelist' => ['nullable', 'array', 'max:5'],
            'dashboard_ip_whitelist.*' => ['nullable', 'string', 'max:45'],
            'api_ip_whitelist' => ['nullable', 'array', 'max:5'],
            'api_ip_whitelist.*' => ['nullable', 'string', 'max:45'],
            'transaction_payment_gateway' => ['nullable', 'string', 'max:160'],
            'withdrawal_payment_gateway' => ['nullable', 'string', 'max:160'],
            'va_payment_gateway' => ['nullable', 'string', 'max:160'],
            'magnetopay_fee_1_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_2_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_3_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_va_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'magnetopay_fee_withdrawal_fixed' => ['nullable', 'integer'],
            'magnetopay_fee_withdrawal_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $slug = $this->uniqueMerchantSlug($data['name']);
        $agent = Agent::query()->with('ma')->findOrFail($data['agent_id']);
        abort_unless($this->canUseAgent($agent), 403);

        $merchant = Merchant::query()->create([
            'agent_id' => $agent->id,
            'slug' => $slug,
            'name' => $data['name'],
            'merchant_id' => $data['merchant_id'] ?? null,
            'merchant_key' => $data['merchant_key'] ?? null,
            'merchant_group_name' => $data['merchant_group_name'] ?? null,
            'merchant_type' => 'cm',
            'gateway' => 'magnetopay',
            'approval_status' => 'approved',
            'topup_enabled' => true,
            'topup_url' => route('topup', ['merchant' => $slug]),
            'transaction_callback_url' => $data['transaction_callback_url'] ?? url('/api/callbacks/hilogate/transaction'),
            'withdrawal_callback_url' => $data['withdrawal_callback_url'] ?? url('/api/callbacks/hilogate/withdrawal'),
            'va_callback_url' => $data['va_callback_url'] ?? null,
            'qris_settlement_type' => $data['qris_settlement_type'] ?? null,
            'va_settlement_type' => $data['va_settlement_type'] ?? null,
            'ip_whitelist_enabled' => (bool) ($data['ip_whitelist_enabled'] ?? false),
            'dashboard_ip_whitelist' => array_values(array_filter($data['dashboard_ip_whitelist'] ?? [])),
            'api_ip_whitelist' => array_values(array_filter($data['api_ip_whitelist'] ?? [])),
            'magnetopay_fee_1_percent' => $data['magnetopay_fee_1_percent'] ?? 0,
            'magnetopay_fee_2_percent' => $data['magnetopay_fee_2_percent'] ?? 0,
            'magnetopay_fee_3_percent' => $data['magnetopay_fee_3_percent'] ?? 0,
            'magnetopay_fee_va_percent' => $data['magnetopay_fee_va_percent'] ?? 0,
            'magnetopay_fee_withdrawal_fixed' => $data['magnetopay_fee_withdrawal_fixed'] ?? null,
            'magnetopay_fee_withdrawal_percent' => $data['magnetopay_fee_withdrawal_percent'] ?? 0,
            'onboarding_payload' => [
                'transaction_payment_gateway' => $data['transaction_payment_gateway'] ?? null,
                'withdrawal_payment_gateway' => $data['withdrawal_payment_gateway'] ?? null,
                'va_payment_gateway' => $data['va_payment_gateway'] ?? null,
            ],
            'approved_at' => now(),
        ]);

        $admins = collect($data['admin_email'])->map(fn (string $email) => User::query()->create([
            'name' => str($email)->before('@')->replace(['.', '_', '-'], ' ')->title()->toString(),
            'email' => $email,
            'role' => 'admin',
            'merchant_id' => $merchant->id,
            'password' => Hash::make(config('paygrid.demo_password')),
            'plain_password' => config('paygrid.demo_password'),
        ]));
        $audit->record('ma.merchant_created', $merchant, null, $merchant->only(['slug', 'name', 'agent_id', 'gateway', 'merchant_type']));
        $admins->each(fn ($admin) => $audit->record('ma.merchant_admin_created', $admin, null, $admin->only(['email', 'role', 'merchant_id'])));

        return back()->with('status', 'Toko Magnetopay berhasil dibuat. Admin default: '.$admins->pluck('email')->implode(', ').' / '.config('paygrid.demo_password').'.');
    }

    /**
     * Report Day/Settlement Day are deliberately not stored - they're always
     * "day_of_week + 1" and computed here for display, matching the mockup
     * (those two cells are read-only badges, never inputs).
     */
    public function commissionForm(): View
    {
        $maId = $this->currentMaId();
        $agents = Agent::query()
            ->when($maId, fn ($query) => $query->where('ma_user_id', $maId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $merchants = Merchant::query()
            ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->orderBy('name')
            ->get(['id', 'name', 'merchant_id', 'approval_status', 'agent_id']);

        $configs = CommissionConfig::query()
            ->when($maId, fn ($query) => $query->where('ma_user_id', $maId))
            ->with(['days', 'merchants:id,name', 'agent:id,name'])
            ->withCount('reports')
            ->latest('id')
            ->get();

        return view('paygrid.ma-commission', [
            'roleLabel' => 'MA',
            'menus' => app(\App\Services\Navigation\MenuBuilder::class)->ma(),
            'active' => 'commission',
            'agents' => $agents,
            'merchants' => $merchants,
            'templates' => $configs->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->name ?: $c->created_at->timezone('Asia/Jakarta')->format('d M Y H:i').' ('.($c->status === 'active' ? 'Aktif' : 'Draft').')',
                'name' => $c->name,
                'agent_id' => $c->agent_id,
                'merchant_id' => $c->merchants->first()?->id,
                'days' => $c->days->map(fn ($d) => [
                    'day_of_week' => $d->day_of_week,
                    'enabled' => $d->enabled,
                    'rate_percent' => (float) $d->rate_percent,
                    'wd_fee_fixed' => (int) $d->wd_fee_fixed,
                    'notes' => $d->notes,
                ]),
            ]),
            'configList' => $configs->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name ?: '(Tanpa nama)',
                'agent_name' => $c->agent?->name ?: '-',
                'scope_label' => $c->apply_scope === 'specific'
                    ? ($c->merchants->pluck('name')->implode(', ') ?: 'Tidak ada toko')
                    : 'Semua toko di group',
                'status' => $c->status,
                'report_count' => $c->reports_count,
            ]),
        ]);
    }

    public function commissionStore(Request $request): RedirectResponse
    {
        $maId = $this->currentMaId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'agent_id' => ['required', Rule::exists('agents', 'id')->where('ma_user_id', $maId)],
            'merchant_id' => ['nullable', Rule::exists('merchants', 'id')->where('agent_id', $request->input('agent_id'))],
            'days' => ['required', 'array', 'size:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'days.*.enabled' => ['nullable', 'boolean'],
            'days.*.rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'days.*.wd_fee_fixed' => ['nullable', 'integer', 'min:0'],
            'days.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $applyScope = $data['merchant_id'] ?? null ? 'specific' : 'all';

        DB::transaction(function () use ($data, $maId, $applyScope, $request) {
            $config = CommissionConfig::query()->create([
                'ma_user_id' => $maId ?? $request->user()->id,
                'name' => $data['name'],
                'agent_id' => $data['agent_id'],
                'status' => 'active',
                'apply_scope' => $applyScope,
            ]);

            foreach ($data['days'] as $day) {
                $config->days()->create([
                    'day_of_week' => $day['day_of_week'],
                    'enabled' => (bool) ($day['enabled'] ?? false),
                    'rate_percent' => $day['rate_percent'],
                    'wd_fee_fixed' => $day['wd_fee_fixed'] ?? 0,
                    'notes' => $day['notes'] ?? null,
                ]);
            }

            if ($applyScope === 'specific') {
                $config->merchants()->sync([$data['merchant_id']]);
            }
        });

        \App\Jobs\BackfillCommissionReportsThisMonth::dispatch()->onQueue('backfill');

        return back()->with('status', 'Konfigurasi komisi tersimpan dan aktif.');
    }

    /**
     * "Copot" - toggles active/inactive rather than deleting. Deactivating
     * just stops future commission:generate-reports runs from using this
     * config (it's filtered out by status='active' there) - the config, its
     * days, and all historical merchant_commission_reports rows stay intact,
     * so it can be reactivated later with zero data loss.
     */
    public function commissionToggleStatus(Request $request, CommissionConfig $config, AuditLogService $audit): RedirectResponse
    {
        $maId = $this->currentMaId();
        abort_unless($request->user()?->role === 'superadmin' || ($maId && $config->ma_user_id === $maId), 403);

        $newStatus = $config->status === 'active' ? 'inactive' : 'active';
        $before = $config->only(['status']);
        $config->update(['status' => $newStatus]);
        $audit->record('ma.commission_config_status_toggled', $config, $before, $config->only(['status']));

        return back()->with('status', $newStatus === 'active'
            ? 'Komisi diaktifkan kembali.'
            : 'Komisi dicopot - toko ini tidak lagi dapat komisi baru, tapi history laporan lama tetap aman.');
    }

    /**
     * Permanent delete - commission_config_days and commission_config_merchants
     * cascade automatically (foreign key ->cascadeOnDelete()), and critically
     * so does merchant_commission_reports.commission_config_id, so this also
     * wipes every historical commission report row this config ever produced.
     * The report_count warning in the view exists specifically so an MA isn't
     * surprised by that - "Copot" (deactivate) is the non-destructive choice
     * when history matters.
     */
    public function commissionDestroy(Request $request, CommissionConfig $config, AuditLogService $audit): RedirectResponse
    {
        $maId = $this->currentMaId();
        abort_unless($request->user()?->role === 'superadmin' || ($maId && $config->ma_user_id === $maId), 403);

        $reportCount = $config->reports()->count();
        $audit->record('ma.commission_config_deleted', $config, $config->only(['name', 'status', 'apply_scope']), ['report_rows_deleted' => $reportCount]);
        $config->delete();

        return back()->with('status', $reportCount > 0
            ? "Template komisi dihapus, termasuk {$reportCount} baris history laporan yang nempel ke config ini."
            : 'Template komisi dihapus.');
    }

    public function insentifForm(): View
    {
        $maId = $this->currentMaId();
        $merchants = Merchant::query()
            ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return view('paygrid.ma-insentif', [
            'roleLabel' => 'MA',
            'menus' => app(\App\Services\Navigation\MenuBuilder::class)->ma(),
            'active' => 'insentif',
            'merchants' => $merchants,
            'defaultMonth' => now('Asia/Jakarta')->format('Y-m'),
        ]);
    }

    /**
     * Reuses feeAmountsByMerchant()'s exact merchant/agent/ma fee formula
     * (already the source of the Overview "Fee MA" KPI) for the "Fee
     * Distribution" columns, so the PDF recap can never drift from what the
     * dashboard already shows for the same stores/period. Combined and
     * Merchant both show the full extracted MDR (volume x merchant_mdr%) -
     * confirmed as intentional with the business, not a typo/duplicate.
     * "Cash Back & Incentives" columns are separate, non-MDR line items:
     * Cashback = volume x cashback_agent_percent (excluded from the MDR
     * waterfall on purpose), Marketing/Disbursement = trx count x their own
     * fixed per-trx rate from the merchant's fee settings.
     */
    public function insentifPdf(Request $request): Response
    {
        $data = $request->validate([
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['integer'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $maId = $this->currentMaId();
        $merchants = Merchant::query()
            ->whereIn('id', $data['store_ids'])
            ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->orderBy('name')
            ->get(['id', 'name']);
        abort_if($merchants->isEmpty(), 404);

        $month = CarbonImmutable::createFromFormat('Y-m', $data['month'], 'Asia/Jakarta');
        $filters = array_merge($this->blankFilters(), [
            'store_ids' => $merchants->pluck('id')->all(),
            'from' => $month->startOfMonth()->toDateString(),
            'to' => $month->endOfMonth()->toDateString(),
        ]);

        $feeRows = $this->feeAmountsByMerchant($filters)->keyBy('merchant_id');
        $extras = $this->insentifExtrasByMerchant($merchants->pluck('id')->all(), $filters);

        $rows = $merchants->map(function ($m) use ($feeRows, $extras) {
            $fee = $feeRows->get($m->id);
            $extra = $extras->get($m->id);

            return [
                'name' => $m->name,
                'volume' => (int) ($fee->volume ?? 0),
                'combined' => (int) round((float) ($fee->merchant_fee ?? 0)),
                'ma' => (int) round((float) ($fee->ma_fee ?? 0)),
                'agent' => (int) round((float) ($fee->agent_fee ?? 0)),
                'merchant' => (int) round((float) ($fee->merchant_fee ?? 0)),
                'cashback' => (int) round((float) ($extra->cashback ?? 0)),
                'marketing' => (int) ($extra->marketing ?? 0),
                'disbursement' => (int) ($extra->disbursement ?? 0),
            ];
        });

        $pdf = Pdf::loadView('paygrid.insentif-pdf-ma', [
            'roleLabel' => 'MA',
            'recipientName' => $request->user()->name ?? 'MA',
            'monthLabel' => $month->format('F Y'),
            'rows' => $rows,
            'totals' => [
                'volume' => $rows->sum('volume'),
                'combined' => $rows->sum('combined'),
                'ma' => $rows->sum('ma'),
                'agent' => $rows->sum('agent'),
                'merchant' => $rows->sum('merchant'),
                'cashback' => $rows->sum('cashback'),
                'marketing' => $rows->sum('marketing'),
                'disbursement' => $rows->sum('disbursement'),
            ],
        ]);

        return $pdf->download('insentif-ma-'.$month->format('Y-m').'.pdf');
    }

    public function commissionReport(Request $request): View
    {
        $maId = $this->currentMaId();
        $monthParam = $request->query('month');
        $month = is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)
            ? CarbonImmutable::createFromFormat('Y-m', $monthParam, 'Asia/Jakarta')
            : now('Asia/Jakarta');
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();

        $agents = Agent::query()
            ->when($maId, fn ($query) => $query->where('ma_user_id', $maId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $agentId = $request->query('agent_id');
        $agentId = $agentId !== null && $agentId !== '' && $agents->contains('id', (int) $agentId) ? (int) $agentId : null;

        $merchants = Merchant::query()
            ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->when($agentId, fn ($query) => $query->where('agent_id', $agentId))
            ->with('agent:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'agent_id']);

        $reportTotals = MerchantCommissionReport::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->whereBetween('transaction_date', [$from, $to])
            ->selectRaw('merchant_id, SUM(volume) as total_volume, SUM(commission_amount) as total_commission, SUM(wd_volume) as total_wd_volume, SUM(wd_commission_amount) as total_wd_commission, COUNT(*) as days_counted')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $monthFilters = array_merge($this->blankFilters(), ['from' => $from, 'to' => $to]);
        $extras = $this->insentifExtrasByMerchant($merchants->pluck('id')->all(), $monthFilters);

        $rows = $merchants->map(function ($m) use ($reportTotals, $extras) {
            $totals = $reportTotals->get($m->id);
            $extra = $extras->get($m->id);

            return [
                'id' => $m->id,
                'name' => $m->name,
                'group' => $m->agent?->name,
                'revenue' => (int) round((float) ($extra->volume ?? 0)),
                'trx' => (int) ($extra->trx_count ?? 0),
                'cashback' => (int) round((float) ($extra->cashback ?? 0)),
                'volume' => (int) ($totals->total_volume ?? 0),
                'days_counted' => (int) ($totals->days_counted ?? 0),
                'commission' => (int) ($totals->total_commission ?? 0),
                'wd_volume' => (int) ($totals->total_wd_volume ?? 0),
                'wd_commission' => (int) ($totals->total_wd_commission ?? 0),
            ];
        })->map(fn ($row) => $row + ['total_commission' => $row['commission'] + $row['wd_commission']])
            ->sortByDesc('total_commission')->values();

        return view('paygrid.ma-commission-report', [
            'roleLabel' => 'MA',
            'menus' => app(\App\Services\Navigation\MenuBuilder::class)->ma(),
            'active' => 'commission-report',
            'rows' => $rows,
            'agents' => $agents,
            'selectedAgentId' => $agentId,
            'totals' => [
                'volume' => $rows->sum('volume'),
                'commission' => $rows->sum('commission'),
                'wd_volume' => $rows->sum('wd_volume'),
                'wd_commission' => $rows->sum('wd_commission'),
                'total_commission' => $rows->sum('total_commission'),
                'active_stores' => $rows->filter(fn ($r) => $r['days_counted'] > 0)->count(),
                'revenue' => $rows->sum('revenue'),
                'trx' => $rows->sum('trx'),
                'cashback' => $rows->sum('cashback'),
            ],
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->translatedFormat('F Y'),
        ]);
    }

    /**
     * The "Cash Back & Incentives" side of the insentif report - kept
     * separate from feeAmountsByMerchant() since that method is shared with
     * the Overview "Fee MA" KPI and these columns are specific to this PDF.
     */
    private function insentifExtrasByMerchant(array $merchantIds, array $filters)
    {
        $rates = Merchant::query()
            ->whereIn('id', $merchantIds)
            ->get(['id', 'cashback_agent_percent', 'marketing_fee_fixed', 'disbursement_fee_fixed'])
            ->keyBy('id');

        $trxCounts = (clone $this->transactionsQuery(array_merge($filters, ['status' => 'success'])))
            ->selectRaw('topup_requests.merchant_id as merchant_id')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(topup_requests.amount), 0) as volume')
            ->groupBy('topup_requests.merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $disbursementCounts = $this->withdrawalsQuery(array_merge($filters, ['report_view' => 'withdrawal', 'status' => 'COMPLETED']))
            ->selectRaw('merchant_withdrawals.merchant_id as merchant_id')
            ->selectRaw('COUNT(*) as trx_count')
            ->groupBy('merchant_withdrawals.merchant_id')
            ->get()
            ->keyBy('merchant_id');

        return collect($merchantIds)->mapWithKeys(function ($merchantId) use ($rates, $trxCounts, $disbursementCounts) {
            $rate = $rates->get($merchantId);
            $volume = (float) ($trxCounts->get($merchantId)->volume ?? 0);
            $collectionTrx = (int) ($trxCounts->get($merchantId)->trx_count ?? 0);
            $disbursementTrx = (int) ($disbursementCounts->get($merchantId)->trx_count ?? 0);

            return [$merchantId => (object) [
                'volume' => $volume,
                'trx_count' => $collectionTrx,
                'cashback' => $rate ? $volume * $rate->cashback_agent_percent / 100 : 0,
                'marketing' => $rate ? $collectionTrx * $rate->marketing_fee_fixed : 0,
                'disbursement' => $rate ? $disbursementTrx * $rate->disbursement_fee_fixed : 0,
            ]];
        });
    }

    /**
     * MA-side equivalent of DashboardController::storeAgentOnboardingLink() -
     * same single-use link flow, but since an MA oversees many agents (not
     * just one), the MA must pick which agent/group the link belongs to.
     */
    /**
     * MA-generated links are deliberately different from the agent's own
     * "Generate Link" flow: 1 agent gets 1 permanent, reusable link instead
     * of a new single-use link every time (agents keep the old single-use/
     * expiring behavior - see DashboardController::storeAgentOnboardingLink).
     * Calling this again for an agent that already has an active reusable
     * link just returns that same link rather than creating a duplicate.
     */
    public function storeOnboardingLink(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'exists:agents,id'],
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_telegram' => ['nullable', 'string', 'max:80'],
        ]);
        $agent = Agent::query()->findOrFail($data['agent_id']);
        abort_unless($this->canUseAgent($agent), 403);

        $existing = AgentOnboardingLink::query()
            ->where('agent_id', $agent->id)
            ->where('kind', 'generic')
            ->where('status', 'active')
            ->where('reusable', true)
            ->first();
        if ($existing) {
            return back()->with('status', 'Agen ini sudah punya link permanen, berikut link-nya.')->with('onboarding_link', route('merchant-registration.token-form', $existing));
        }

        $link = DB::transaction(function () use ($agent, $data) {
            AgentOnboardingLink::query()
                ->where('agent_id', $agent->id)
                ->where('kind', 'generic')
                ->where('status', 'active')
                ->update(['status' => 'expired', 'expires_at' => now()]);

            return AgentOnboardingLink::query()->create([
                'agent_id' => $agent->id,
                'kind' => 'generic',
                'token' => Str::random(48),
                'recipient_email' => $data['recipient_email'] ?? null,
                'recipient_telegram' => $data['recipient_telegram'] ?? null,
                'status' => 'active',
                'reusable' => true,
                'expires_at' => null,
            ]);
        });
        $audit->record('ma.onboarding_link_created', $link, null, $link->only(['agent_id', 'recipient_email', 'recipient_telegram', 'status', 'reusable']));

        return back()->with('status', 'Link permanen berhasil dibuat.')->with('onboarding_link', route('merchant-registration.token-form', $link));
    }

    /**
     * Settlement Type + MDR are chosen here (not just at approval) so every
     * submission through this specific link already carries agreed terms -
     * that means each "Generate Link" click now always produces a fresh link
     * (no more "return the existing one unchanged"), since the whole point is
     * to let a new generate carry different terms than the last one.
     */
    public function storeOnboardingLinkMagnetopay(Request $request, AuditLogService $audit, FeeMenuCatalog $feeMenus): RedirectResponse
    {
        $request->merge(['fee_menu_rates' => $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'merchant')]);
        $data = $request->validate([
            'agent_id' => ['required', 'exists:agents,id'],
            'recipient_email' => ['nullable', 'email', 'max:160'],
            'recipient_telegram' => ['nullable', 'string', 'max:80'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('merchant', null), new ExactlyOneFeeMenuFilled()],
            'disbursement_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disbursement_fee_fixed' => ['nullable', 'integer'],
        ]);
        $agent = Agent::query()->findOrFail($data['agent_id']);
        abort_unless($this->canUseAgent($agent), 403);

        $activeMenu = array_key_first(array_filter($data['fee_menu_rates']));

        $link = DB::transaction(function () use ($agent, $data, $activeMenu) {
            AgentOnboardingLink::query()
                ->where('agent_id', $agent->id)
                ->where('kind', 'magnetopay')
                ->where('status', 'active')
                ->update(['status' => 'expired', 'expires_at' => now()]);

            return AgentOnboardingLink::query()->create([
                'agent_id' => $agent->id,
                'kind' => 'magnetopay',
                'token' => Str::random(48),
                'form_number' => $this->nextOnboardingLinkFormNumber(),
                'created_by_user_id' => auth()->id(),
                'fee_menu' => $activeMenu,
                'fee_menu_rates' => $data['fee_menu_rates'],
                'disbursement_fee_percent' => $data['disbursement_fee_percent'] ?? 0,
                'disbursement_fee_fixed' => $data['disbursement_fee_fixed'] ?? null,
                'recipient_email' => $data['recipient_email'] ?? null,
                'recipient_telegram' => $data['recipient_telegram'] ?? null,
                'status' => 'active',
                'reusable' => true,
                'expires_at' => null,
            ]);
        });
        $audit->record('ma.onboarding_link_created', $link, null, $link->only(['agent_id', 'kind', 'form_number', 'fee_menu', 'disbursement_fee_percent', 'disbursement_fee_fixed', 'recipient_email', 'recipient_telegram', 'status', 'reusable']));

        return back()->with('status', 'Link Magnetopay berhasil dibuat ('.$link->form_number.').')->with('onboarding_link_magnetopay', route('merchant-registration.magnetopay.token-form', $link));
    }

    private function nextOnboardingLinkFormNumber(): string
    {
        return 'REG-'.str_pad((string) (((int) AgentOnboardingLink::query()->max('id')) + 1), 6, '0', STR_PAD_LEFT);
    }

    public function expireOnboardingLink(AgentOnboardingLink $link, AuditLogService $audit): RedirectResponse
    {
        abort_unless($this->canUseAgent($link->agent), 403);
        abort_unless($link->status === 'active', 422);

        $before = $link->only(['status', 'expires_at']);
        $link->update(['status' => 'expired', 'expires_at' => now()]);
        $audit->record('ma.onboarding_link_expired', $link, $before, $link->only(array_keys($before)));

        return back()->with('status', 'Link onboarding berhasil di-expire.');
    }

    public function bulkExpireOnboardingLinks(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:expire'],
            'link_ids' => ['required', 'array'],
            'link_ids.*' => ['integer'],
        ]);
        $maId = $this->currentMaId();
        $links = AgentOnboardingLink::query()
            ->when($maId !== null, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
            ->whereIn('id', $data['link_ids'])
            ->where('status', 'active')
            ->get();

        foreach ($links as $link) {
            $before = $link->only(['status', 'expires_at']);
            $link->update(['status' => 'expired', 'expires_at' => now()]);
            $audit->record('ma.onboarding_link_bulk_expired', $link, $before, $link->only(array_keys($before)));
        }

        return back()->with('status', $links->count().' link berhasil di-expire.');
    }

    public function updateAgentFee(Request $request, Agent $agent, AuditLogService $audit, FeeMenuCatalog $feeMenus, FeeSyncService $feeSync): RedirectResponse
    {
        abort_unless($this->canUseAgent($agent), 403);
        $request->merge(['fee_menu_rates' => $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'agent')]);
        $maRates = (array) ($agent->ma->fee_menu_rates ?? []);
        $data = $request->validate([
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('agent', null), new FeeMenuRatesAboveReference('agent', $maRates, 'Based Fee MA')],
            'disbursement_fee_fixed' => ['nullable', 'integer', 'min:0'],
        ]);
        $before = $agent->only(['fee_menu_rates', 'disbursement_fee_fixed']);
        $agent->forceFill($data)->save();
        $feeSync->resyncAgent($agent);
        $audit->record('ma.agent_fee_updated', $agent, $before, $agent->only(array_keys($before)));

        return back()->with('status', 'Fee agen berhasil disimpan.');
    }

    public function updateStoreFee(Request $request, Merchant $merchant, AuditLogService $audit, FeeMenuCatalog $feeMenus, FeeSyncService $feeSync): RedirectResponse
    {
        abort_unless($this->canUseMerchant($merchant), 403);
        $this->normalizePercentInputs($request, ['payin_fee_percent', 'cashback_agent_percent']);
        $rates = $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'merchant');
        $request->merge(['fee_menu_rates' => $rates]);
        $agent = $merchant->agent()->with('ma')->firstOrFail();
        $agentRates = (array) ($agent->fee_menu_rates ?? []);
        $this->normalizePercentInputs($request, ['disbursement_fee_percent', 'marketing_fee_percent']);
        $data = $request->validate([
            'payin_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'cashback_agent_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'cashback_trx_toko_amount' => ['required', 'integer', 'min:0'],
            'cashback_trx_agent_amount' => ['required', 'integer', 'min:0'],
            'disbursement_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disbursement_fee_fixed' => ['nullable', 'integer', 'min:0'],
            'marketing_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'marketing_fee_fixed' => ['nullable', 'integer', 'min:0'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('merchant', null), new ExactlyOneFeeMenuFilled(), new FeeMenuRatesAboveReference('merchant', $agentRates, 'Based Fee Agent')],
        ]);
        $data['fee_menu'] = array_key_first(array_filter($rates));
        $data['settlement_method'] = $feeMenus->settlementMethod($data['fee_menu']);
        $data = array_merge($data, $feeSync->snapshotFor($agent, $data['fee_menu'], $rates[$data['fee_menu']]));
        $data['disbursement_fee_percent'] = $data['disbursement_fee_percent'] ?? 0;
        $data['marketing_fee_percent'] = $data['marketing_fee_percent'] ?? 0;
        $before = $merchant->only(['merchant_mdr_percent', 'payin_fee_percent', 'cashback_agent_percent', 'cashback_trx_toko_amount', 'cashback_trx_agent_amount', 'fee_menu', 'fee_menu_rates', 'settlement_method', 'disbursement_fee_percent', 'disbursement_fee_fixed', 'marketing_fee_percent', 'marketing_fee_fixed']);
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
            'report_view' => (string) request('report_view', 'transaksi'),
            'from' => request('from'),
            'to' => request('to'),
            'store_ids' => array_filter((array) request('store_ids', []), fn ($id) => ctype_digit((string) $id)),
            'statuses' => array_filter((array) request('statuses', [])),
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

    public function botMonitoringExport(TelegramBotMonitoringService $service): Response
    {
        return response($service->toCsv($this->botMonitoringFilters()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bot-monitoring-tickets.csv"',
        ]);
    }

    private function blankFilters(): array
    {
        return ['q' => '', 'status' => 'all', 'agent_id' => 'all', 'store_id' => 'all', 'agents_view' => 'top', 'period' => 'this_month', 'type' => 'all', 'report_view' => 'transaksi', 'from' => null, 'to' => null, 'store_ids' => [], 'statuses' => []];
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

        // Only 'custom' lets the raw from/to inputs win - for every other
        // period the Dari/Sampai date fields keep whatever value was last
        // typed (browsers don't clear them on their own), so honoring those
        // unconditionally here silently overrode presets like "Bulan Lalu"
        // with whatever stale dates happened to still be in the inputs.
        if ($filters['period'] === 'custom' && ($filters['from'] || $filters['to'])) {
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

    public function magnetopaySubmissions(Request $request): View
    {
        $maId = $this->currentMaId();
        $filters = $this->filters();

        $registrations = MerchantRegistration::query()
            ->with(['agent', 'merchant'])
            ->where('gateway', 'magnetopay')
            ->when($maId, fn ($query) => $query->where(
                fn ($nested) => $nested->whereRelation('agent', 'ma_user_id', $maId)->when(! $this->isDemoUser(), fn ($n) => $n->orWhereNull('agent_id'))
            ))
            ->when($filters['q'] !== '', fn ($query) => $query->where('store_name', 'like', $filters['q'].'%'))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['agent_id'] !== 'all', fn ($query) => $query->where('agent_id', $filters['agent_id']))
            ->latest()
            ->get();

        $agents = Agent::query()
            ->when($maId, fn ($query) => $query->where('ma_user_id', $maId))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('paygrid.ma-magnetopay-submissions', [
            'roleLabel' => $request->user()?->role === 'magneto_ops' ? 'Magneto Ops' : 'MA',
            'menus' => app(\App\Services\Navigation\MenuBuilder::class)->ma(),
            'active' => 'magnetopay-submissions',
            'registrations' => $registrations,
            'agents' => $agents,
            'filters' => $filters,
        ]);
    }

    public function updateMagnetopaySubmission(Request $request, MerchantRegistration $registration, AuditLogService $audit): RedirectResponse
    {
        $maId = $this->currentMaId();
        // magneto_ops (e.g. Yesika) handles Magnetopay intake for every MA, not
        // just one - same unrestricted treatment as superadmin here, scoped
        // down everywhere else only by which routes/menu items that role has.
        if (! in_array($request->user()?->role, ['superadmin', 'magneto_ops'], true)) {
            $registration->loadMissing('agent');
            $ownedByThisMa = $registration->agent_id === null || (int) $registration->agent?->ma_user_id === $maId;
            abort_unless($request->user()?->role === 'ma' && $ownedByThisMa, 403);
        }

        $data = $request->validate([
            'agent_id' => ['nullable', Rule::exists('agents', 'id')->when($maId, fn ($rule) => $rule->where('ma_user_id', $maId))],
            'merchant_key' => ['nullable', 'string', 'max:255'],
            // Named gateway_merchant_id (not merchant_id) to avoid colliding with
            // $registration->merchant_id below, which is the FK to the approved
            // Merchant row - a completely different thing from the gateway's own
            // merchant identifier string stored on that Merchant.
            'gateway_merchant_id' => ['nullable', 'string', 'max:160'],
        ]);

        $before = $registration->only(['agent_id']);
        $payload = (array) ($registration->payload ?? []);
        if (array_key_exists('merchant_key', $data)) {
            $payload['merchant_key'] = $data['merchant_key'];
        }
        if (array_key_exists('gateway_merchant_id', $data)) {
            $payload['merchant_id'] = $data['gateway_merchant_id'];
        }
        $registration->update([
            'agent_id' => $data['agent_id'] ?? null,
            'payload' => $payload,
        ]);

        if ($registration->merchant_id) {
            // merchant_key is cast 'encrypted' on the model - a query-builder
            // ->update() bypasses casts entirely and would write the plain
            // secret key unencrypted, so this goes through a real model
            // instance instead.
            Merchant::query()->find($registration->merchant_id)?->update([
                'agent_id' => $data['agent_id'] ?? null,
                'merchant_key' => $data['merchant_key'] ?? null,
                'merchant_id' => $data['gateway_merchant_id'] ?? null,
            ]);
        }

        $audit->record('ma.magnetopay_submission_updated', $registration, $before, $registration->only(['agent_id']));

        return back()->with('status', 'Submission berhasil diupdate.');
    }

    private function registrations(array $filters)
    {
        return MerchantRegistration::query()->with('agent')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->where(
                fn ($nested) => $nested->whereRelation('agent', 'ma_user_id', $maId)->when(! $this->isDemoUser(), fn ($n) => $n->orWhereNull('agent_id'))
            ))
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('store_name', 'like', $filters['q'].'%')->orWhere('engine_name', 'like', $filters['q'].'%')->orWhereRelation('agent', 'name', 'like', $filters['q'].'%')))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->orderByRaw("CASE WHEN status IN ('pending', 'pending_agent', 'pending_ma') THEN 0 WHEN status = 'approved' THEN 1 WHEN status = 'rejected' THEN 2 ELSE 3 END")
            ->latest();
    }

    /**
     * Unfiltered counts for the three status pills on the approval page
     * header - independent of whatever status/q filter is currently applied
     * to the table below them.
     */
    private function approvalCounts(): array
    {
        $base = MerchantRegistration::query()
            ->when($this->currentMaId(), fn ($query, $maId) => $query->where(
                fn ($nested) => $nested->whereRelation('agent', 'ma_user_id', $maId)->when(! $this->isDemoUser(), fn ($n) => $n->orWhereNull('agent_id'))
            ));

        return [
            'pending' => (clone $base)->whereIn('status', ['pending', 'pending_agent', 'pending_ma'])->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
        ];
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
            ->when(! empty($filters['statuses']), fn ($query) => $query->whereIn('topup_requests.status', $filters['statuses']))
            ->when($filters['store_id'] !== 'all', fn ($query) => $query->where('topup_requests.merchant_id', $filters['store_id']))
            ->when(! empty($filters['store_ids']), fn ($query) => $query->whereIn('topup_requests.merchant_id', $filters['store_ids']));
    }

    /**
     * merchant_daily_metrics only tracks 3 coarse status buckets (success /
     * pending / expired+failed+rejected combined) and has no payment_id/rrn/
     * customer_reference columns to search - so the rollup fast path is only
     * correct when the caller isn't narrowing by free-text search or by a
     * specific status finer than those buckets. agent_id/store_id/store_ids
     * narrowing is fine (the rollup is keyed by merchant_id).
     */
    private function canUseMetricRollup(array $filters): bool
    {
        return $filters['q'] === '' && $filters['status'] === 'all' && empty($filters['statuses']);
    }

    private function rollupScopedMerchantIds(array $filters)
    {
        $maId = $this->currentMaId();

        $scopedMerchantIds = ($maId || $filters['agent_id'] !== 'all')
            ? Merchant::query()
                ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
                ->when($filters['agent_id'] !== 'all', fn ($query) => $query->where('agent_id', $filters['agent_id']))
                ->pluck('id')
            : null;

        if ($filters['store_id'] !== 'all') {
            $scopedMerchantIds = $scopedMerchantIds !== null
                ? $scopedMerchantIds->intersect([(int) $filters['store_id']])->values()
                : collect([(int) $filters['store_id']]);
        }

        if (! empty($filters['store_ids'])) {
            $scopedMerchantIds = $scopedMerchantIds !== null
                ? $scopedMerchantIds->intersect($filters['store_ids'])->values()
                : collect($filters['store_ids']);
        }

        return $scopedMerchantIds;
    }

    private function metricRollupQuery(array $filters)
    {
        $merchantIds = $this->rollupScopedMerchantIds($filters);

        return MerchantDailyMetric::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->when($filters['from'], fn ($query) => $query->whereDate('metric_date', '>=', $this->wibDate($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->whereDate('metric_date', '<=', $this->wibDate($filters['to'])));
    }

    private function transactions(array $filters)
    {
        return $this->transactionsQuery($filters)
            ->select('topup_requests.*')
            ->with(['merchant.agent', 'feeSnapshot'])
            ->latest('topup_requests.submitted_at');
    }

    /**
     * Mirrors transactionsQuery() - same MA/agent/store scoping, same period
     * filter - just against merchant_withdrawals (synced from the gateway)
     * instead of topup_requests. 'status' here reuses the request's own
     * status filter but against withdrawal's COMPLETED/PENDING/FAILED values
     * rather than topup's success/pending/expired/etc, so it only applies
     * when the request is actually scoped to the withdrawal report view.
     */
    private function withdrawalsQuery(array $filters)
    {
        $maId = $this->currentMaId();

        $scopedMerchantIds = ($maId || $filters['agent_id'] !== 'all')
            ? Merchant::query()
                ->when($maId, fn ($query) => $query->whereRelation('agent', 'ma_user_id', $maId))
                ->when($filters['agent_id'] !== 'all', fn ($query) => $query->where('agent_id', $filters['agent_id']))
                ->pluck('id')
            : null;

        return MerchantWithdrawal::query()
            ->when($scopedMerchantIds !== null, fn ($query) => $query->whereIn('merchant_withdrawals.merchant_id', $scopedMerchantIds))
            ->when($filters['from'], fn ($query) => $query->where('merchant_withdrawals.gateway_created_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('merchant_withdrawals.gateway_created_at', '<=', $this->rangeEnd($filters['to'])))
            ->when($filters['store_id'] !== 'all', fn ($query) => $query->where('merchant_withdrawals.merchant_id', $filters['store_id']))
            ->when(! empty($filters['store_ids']), fn ($query) => $query->whereIn('merchant_withdrawals.merchant_id', $filters['store_ids']))
            ->when($filters['report_view'] === 'withdrawal' && in_array($filters['status'], ['COMPLETED', 'PENDING', 'FAILED'], true), fn ($query) => $query->where('merchant_withdrawals.status', $filters['status']))
            ->when($filters['report_view'] === 'withdrawal' && ! empty($filters['statuses']), fn ($query) => $query->whereIn('merchant_withdrawals.status', array_map('strtoupper', $filters['statuses'])));
    }

    private function withdrawals(array $filters)
    {
        return $this->withdrawalsQuery($filters)
            ->select('merchant_withdrawals.*')
            ->with('merchant.agent')
            ->latest('merchant_withdrawals.gateway_created_at');
    }

    private function selectedStoreWithdrawalStats(array $filters): array
    {
        $row = (clone $this->withdrawalsQuery($filters))
            ->selectRaw("COUNT(*) as trx_total")
            ->selectRaw("SUM(CASE WHEN merchant_withdrawals.status = 'COMPLETED' THEN 1 ELSE 0 END) as trx_completed")
            ->selectRaw("SUM(CASE WHEN merchant_withdrawals.status = 'PENDING' THEN 1 ELSE 0 END) as trx_pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN merchant_withdrawals.status = 'COMPLETED' THEN merchant_withdrawals.amount ELSE 0 END), 0) as amount_completed")
            ->selectRaw("COALESCE(SUM(CASE WHEN merchant_withdrawals.status = 'PENDING' THEN merchant_withdrawals.amount ELSE 0 END), 0) as amount_pending")
            ->first();

        return [
            'trx_total' => (int) ($row->trx_total ?? 0),
            'trx_completed' => (int) ($row->trx_completed ?? 0),
            'trx_pending' => (int) ($row->trx_pending ?? 0),
            'amount_completed' => (int) ($row->amount_completed ?? 0),
            'amount_pending' => (int) ($row->amount_pending ?? 0),
        ];
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

        if ($filters['report_view'] === 'withdrawal') {
            $totals = MerchantWithdrawal::query()
                ->whereIn('merchant_id', $stores->pluck('id'))
                ->where('status', 'COMPLETED')
                ->when($filters['from'], fn ($query) => $query->where('gateway_created_at', '>=', $this->rangeStart($filters['from'])))
                ->when($filters['to'], fn ($query) => $query->where('gateway_created_at', '<=', $this->rangeEnd($filters['to'])))
                ->selectRaw('merchant_id, COUNT(*) as trx_total')
                ->selectRaw('COALESCE(SUM(amount), 0) as volume_success')
                ->groupBy('merchant_id')
                ->get()
                ->keyBy('merchant_id');
            // All-time, not status/period filtered - a store's "last active" shouldn't
            // disappear just because its only recent attempt failed or fell outside
            // the report's selected date range.
            $lastActivity = MerchantWithdrawal::query()
                ->whereIn('merchant_id', $stores->pluck('id'))
                ->selectRaw('merchant_id, MAX(gateway_created_at) as last_transaction_at')
                ->groupBy('merchant_id')
                ->get()
                ->keyBy('merchant_id');
        } else {
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
        }

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
        $totals = $this->canUseMetricRollup($filters)
            ? $this->metricRollupQuery($filters)
                ->selectRaw('COALESCE(SUM(trx_success), 0) as trx_total')
                ->selectRaw('COALESCE(SUM(amount_success), 0) as volume_success')
                ->selectRaw('COALESCE(SUM(net_success), 0) as total_settlement')
                ->selectRaw('COALESCE(SUM(amount_pending), 0) as pending_transaction_amount')
                ->selectRaw('COALESCE(SUM(trx_pending), 0) as trx_pending')
                ->selectRaw('COALESCE(SUM(trx_expired), 0) as trx_expired')
                ->first()
            : (clone $this->transactionsQuery($filters))
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
            'fee_merchant' => $fee['merchant'],
            'available_balance' => (int) ($balances->active ?? 0),
            'pending_settlement' => (int) ($balances->pending ?? 0),
        ];
    }

    private function reportAgents(array $filters)
    {
        // agent_id is denormalized directly onto merchant_daily_metrics at
        // write time, so this reads from the ~5k-row rollup instead of
        // joining+scanning the 1M+-row topup_requests table - no merchant/
        // status/text filters are accepted by this method, so there's no
        // rollup-coverage gap to guard against here.
        $metrics = MerchantDailyMetric::query()
            ->join('agents', 'agents.id', '=', 'merchant_daily_metrics.agent_id')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->where('agents.ma_user_id', $maId))
            ->when($filters['from'], fn ($query) => $query->whereDate('merchant_daily_metrics.metric_date', '>=', $this->wibDate($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->whereDate('merchant_daily_metrics.metric_date', '<=', $this->wibDate($filters['to'])))
            ->selectRaw('merchant_daily_metrics.agent_id, COALESCE(SUM(merchant_daily_metrics.amount_success), 0) as volume')
            ->selectRaw('COALESCE(SUM(merchant_daily_metrics.trx_pending), 0) as pending')
            ->selectRaw('COALESCE(SUM(merchant_daily_metrics.trx_success), 0) as settled')
            ->whereNotNull('merchant_daily_metrics.agent_id')
            ->groupBy('merchant_daily_metrics.agent_id')
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

    private function reportAgentsWithdrawal(array $filters)
    {
        $metrics = MerchantWithdrawal::query()
            ->join('merchants', 'merchants.id', '=', 'merchant_withdrawals.merchant_id')
            ->join('agents', 'agents.id', '=', 'merchants.agent_id')
            ->when($this->currentMaId(), fn ($query, $maId) => $query->where('agents.ma_user_id', $maId))
            ->when($filters['from'], fn ($query) => $query->where('merchant_withdrawals.gateway_created_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('merchant_withdrawals.gateway_created_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw("merchants.agent_id, COALESCE(SUM(CASE WHEN merchant_withdrawals.status = 'COMPLETED' THEN merchant_withdrawals.amount ELSE 0 END), 0) as volume")
            ->selectRaw("SUM(CASE WHEN merchant_withdrawals.status = 'PENDING' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN merchant_withdrawals.status = 'COMPLETED' THEN 1 ELSE 0 END) as settled")
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
        // trx_total here means every attempted transaction regardless of
        // status, not just successes - trx_success + trx_pending + trx_expired
        // covers the whole enum (success/pending/expired/failed/rejected), so
        // summing those 3 rollup buckets reproduces COUNT(*) exactly.
        $row = $this->canUseMetricRollup($filters)
            ? $this->metricRollupQuery($filters)
                ->selectRaw('COALESCE(SUM(trx_success + trx_pending + trx_expired), 0) as trx_total')
                ->selectRaw('COALESCE(SUM(trx_success), 0) as trx_success')
                ->selectRaw('COALESCE(SUM(trx_pending), 0) as trx_pending')
                ->selectRaw('COALESCE(SUM(amount_success), 0) as amount_success')
                ->selectRaw('COALESCE(SUM(net_success), 0) as settlement')
                ->first()
            : (clone $this->transactionsQuery($filters))
                ->selectRaw('COUNT(*) as trx_total')
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
        // status is forced to 'success' either way, so only the free-text
        // search and the extra statuses[] filter (which transactionsQuery
        // would stack on top of the forced 'success', not replace) need to
        // be absent for the rollup path to reproduce the same result.
        $volumesByMerchant = ($filters['q'] === '' && empty($filters['statuses']))
            ? $this->metricRollupQuery($filters)
                ->selectRaw('merchant_id, COALESCE(SUM(amount_success), 0) as volume')
                ->groupBy('merchant_id')
                ->get()
            : (clone $this->transactionsQuery(array_merge($filters, ['status' => 'success'])))
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
        $volumesByMerchant = ($filters['q'] === '' && empty($filters['statuses']))
            ? $this->metricRollupQuery($filters)
                ->selectRaw('merchant_id, COALESCE(SUM(amount_success), 0) as volume')
                ->groupBy('merchant_id')
                ->get()
                ->keyBy('merchant_id')
            : (clone $this->transactionsQuery(array_merge($filters, ['status' => 'success'])))
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

    private function cachedAnalytics(string $key, array $filters, \Closure $callback, int $ttl = 180): array
    {
        // 'to' for relative periods (e.g. "this_month") resolves to now() fresh on
        // every request, which would make almost every request a unique cache key.
        // Bucket from/to to the nearest 3-minute window so requests landing in the
        // same window can still share a cached result even when $ttl is shorter
        // (a shorter TTL just means the entry expires - and gets recomputed -
        // before the bucket rolls over; harmless, not a bug).
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
        return Cache::remember($cacheKey, $ttl, fn () => json_decode(json_encode($callback()), true));
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
     * in the same Analytics page load (both cache misses), the scan only
     * happens once instead of twice.
     *
     * Reads from the merchant_daily_metrics rollup (keyed by WIB calendar day,
     * not exact timestamp) rather than raw topup_requests - callers only use
     * this for trend/growth comparisons (leaderboard, revenue concentration),
     * never exact settlement totals, so rounding $from/$to to their WIB day
     * is an acceptable tradeoff for scanning ~227x fewer rows. $from/$to can
     * fall mid-day (e.g. the "previous period" window is "now minus N
     * seconds", not midnight-aligned) - this widens that window to whole days.
     */
    private function merchantVolumeForWindow($merchantIds, ?CarbonImmutable $from, CarbonImmutable $to)
    {
        $key = json_encode([$merchantIds?->values()->all(), $from?->toIso8601String(), $to->toIso8601String()]);

        if ($this->merchantVolumeForWindowCacheKey === $key) {
            return $this->merchantVolumeForWindowCache;
        }

        $rows = MerchantDailyMetric::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->when($from, fn ($query) => $query->whereDate('metric_date', '>=', $from->copy()->setTimezone('Asia/Jakarta')->toDateString()))
            ->whereDate('metric_date', '<=', $to->copy()->setTimezone('Asia/Jakarta')->toDateString())
            ->selectRaw('merchant_id, COALESCE(SUM(amount_success), 0) as volume')
            ->groupBy('merchant_id')
            ->get();

        $this->merchantVolumeForWindowCacheKey = $key;
        $this->merchantVolumeForWindowCache = $rows;

        return $rows;
    }

    private function analyticsBisnis(array $filters): array
    {
        // metric_date is already a WIB calendar day (MetricRollupService
        // computes it that way), so no CONVERT_TZ expression needed here.
        $merchantIds = $this->maScopedMerchantIds();

        $rows = MerchantDailyMetric::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->when($filters['from'], fn ($query) => $query->whereDate('metric_date', '>=', $this->wibDate($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->whereDate('metric_date', '<=', $this->wibDate($filters['to'])))
            ->selectRaw('DATE(metric_date) as d')
            ->selectRaw('COALESCE(SUM(amount_success), 0) as gmv')
            ->selectRaw('COALESCE(SUM(fee_total), 0) as fee')
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
            ->map(fn ($group) => $group->map(fn (Merchant $merchant) => ['id' => $merchant->id, 'name' => $merchant->name, 'agent_id' => $merchant->agent_id])->values())
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
        $from = now('Asia/Jakarta')->subDays(29)->toDateString();
        $to = now('Asia/Jakarta')->toDateString();
        $merchantIds = $this->maScopedMerchantIds();

        $rows = MerchantDailyMetric::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->selectRaw('DATE(metric_date) as d, COALESCE(SUM(amount_success), 0) as volume')
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

    /**
     * Plain ticket-count overview (how many, resolved vs not) to sit
     * alongside analyticsTicketSla()'s timing/percentage view - same
     * support_tickets source and same period filter for consistency within
     * this tab.
     */
    private function analyticsTicketVolume(array $filters): array
    {
        $merchantIds = $this->maScopedMerchantIds();

        $byStatus = SupportTicket::query()
            ->when($merchantIds !== null, fn ($query) => $query->whereIn('merchant_id', $merchantIds))
            ->when($filters['from'], fn ($query) => $query->where('created_at', '>=', $this->rangeStart($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->where('created_at', '<=', $this->rangeEnd($filters['to'])))
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $done = (int) ($byStatus['done'] ?? 0);
        $cancelled = (int) ($byStatus['cancelled'] ?? 0);
        $total = (int) $byStatus->sum();

        return [
            'total' => $total,
            'done' => $done,
            'cancelled' => $cancelled,
            'open' => $total - $done - $cancelled,
            'byStatus' => [
                'not_started' => (int) ($byStatus['not_started'] ?? 0),
                'open' => (int) ($byStatus['open'] ?? 0),
                'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
                'done' => $done,
                'cancelled' => $cancelled,
            ],
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

    /**
     * Not period-filtered (last 24h fixed window) - this is "what's broken
     * right now", same convention as analyticsProvisioningFailures(). Pulls
     * from gateway_sync_logs, which every pull/sync job already writes to
     * regardless of failure reason - an IP-whitelist rejection is flagged
     * specifically (string-matched from the gateway's own error message)
     * since that's the single most common and most actionable cause.
     */
    private function analyticsConnectivityIssues(): array
    {
        $merchantIds = $this->merchants($this->blankFilters())->pluck('id');
        $since = now('Asia/Jakarta')->subDay()->utc();

        $rows = GatewaySyncLog::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', 'failed')
            ->where('created_at', '>=', $since)
            ->with('merchant:id,name')
            ->get()
            ->groupBy('merchant_id')
            ->map(function ($group) {
                $latest = $group->sortByDesc('created_at')->first();
                $isIpIssue = str_contains(mb_strtolower((string) $latest->message), 'whitelist');

                return [
                    'merchant_name' => $latest->merchant?->name ?: '-',
                    'fail_count' => $group->count(),
                    'issue_type' => $isIpIssue ? 'IP Belum Whitelist' : 'Lainnya',
                    'last_message' => Str::limit((string) $latest->message, 160),
                    'last_attempt' => optional($latest->created_at)->timezone('Asia/Jakarta')->format('d M Y H:i'),
                ];
            })
            ->sortByDesc('fail_count')
            ->values();

        return [
            'rows' => $rows,
            'totalMerchantsAffected' => $rows->count(),
            'totalIpIssues' => $rows->where('issue_type', 'IP Belum Whitelist')->count(),
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
        return MerchantDailyMetric::query()
            ->whereIn('merchant_id', $merchantIds)
            ->when($filters['from'], fn ($query) => $query->whereDate('metric_date', '>=', $this->wibDate($filters['from'])))
            ->when($filters['to'], fn ($query) => $query->whereDate('metric_date', '<=', $this->wibDate($filters['to'])))
            ->selectRaw('merchant_id, COALESCE(SUM(trx_success), 0) as trx_total')
            ->selectRaw('COALESCE(SUM(amount_success), 0) as volume_success')
            ->selectRaw('COALESCE(SUM(net_success), 0) as settlement')
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
        $since = now('Asia/Jakarta')->subDays($days - 1)->toDateString();

        // merchant_daily_metrics already stores these exact buckets per WIB
        // day (see MetricRollupService) - reading the ~5k-row rollup here
        // instead of scanning raw topup_requests is the single biggest win
        // of this change, since this powers the Overview page's hero tiles
        // and sparkline on every load.
        $rows = MerchantDailyMetric::query()
            ->whereIn('merchant_id', $merchantIds)
            ->whereDate('metric_date', '>=', $since)
            ->selectRaw('DATE(metric_date) as d')
            ->selectRaw('COALESCE(SUM(trx_success), 0) as trx_success')
            ->selectRaw('COALESCE(SUM(amount_success), 0) as volume_success')
            ->selectRaw('COALESCE(SUM(fee_total), 0) as fee')
            ->selectRaw('COALESCE(SUM(trx_pending), 0) as trx_pending')
            ->selectRaw('COALESCE(SUM(amount_pending), 0) as pending_amount')
            ->selectRaw('COALESCE(SUM(trx_expired), 0) as trx_risk')
            ->selectRaw('COALESCE(SUM(amount_expired), 0) as risk_amount')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $withdrawalDateExpr = $isSqlite ? "date(gateway_created_at, '+7 hours')" : "DATE(CONVERT_TZ(gateway_created_at, '+00:00', '+07:00'))";
        $withdrawalRows = MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('gateway_created_at', '>=', $since)
            ->selectRaw("{$withdrawalDateExpr} as d")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN amount ELSE 0 END), 0) as withdrawal")
            ->selectRaw("SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as withdrawal_trx")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'PENDING' THEN amount ELSE 0 END), 0) as withdrawal_pending_amount")
            ->selectRaw("SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as withdrawal_pending_trx")
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
                'withdrawal_trx' => (int) ($withdrawalRows->get($date)->withdrawal_trx ?? 0),
                'withdrawal_pending_amount' => (int) ($withdrawalRows->get($date)->withdrawal_pending_amount ?? 0),
                'withdrawal_pending_trx' => (int) ($withdrawalRows->get($date)->withdrawal_pending_trx ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Today vs yesterday, 24 hourly buckets each (WIB) - powers the Overview
     * "Transaction Flow" chart's default hourly view (1H/6H/24H are all just
     * PHP-side slices of this same 24-bucket array, no extra queries).
     */
    private function overviewHourlyFlow($merchantIds, array $topChannels = [], array $topMerchantIds = []): array
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
            ->where('gateway_created_at', '>=', $yesterdayStart->utc())
            ->where('gateway_created_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw("{$withdrawalDateExpr} as d, {$withdrawalHourExpr} as h")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN amount ELSE 0 END), 0) as withdrawal")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'PENDING' THEN amount ELSE 0 END), 0) as withdrawal_pending")
            ->groupBy('d', 'h')
            ->get()
            ->groupBy('d');

        // QRIS Source / Top Merchant breakdown: hourly (today only) for the
        // 1D range, plus a daily version (last 30 days) so the 7D/Month range
        // toggle on the Analytics card has something to slice from.
        $sourceRows = empty($topChannels) ? collect() : TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->whereIn('channel', $topChannels)
            ->where('status', 'success')
            ->where('submitted_at', '>=', $todayStart->copy()->utc())
            ->where('submitted_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw("{$hourExpr} as h, channel")
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('h', 'channel')
            ->get()
            ->groupBy('channel');

        $merchantRows = empty($topMerchantIds) ? collect() : TopupRequest::query()
            ->whereIn('merchant_id', $topMerchantIds)
            ->where('status', 'success')
            ->where('submitted_at', '>=', $todayStart->copy()->utc())
            ->where('submitted_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw("{$hourExpr} as h, merchant_id")
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('h', 'merchant_id')
            ->get()
            ->groupBy('merchant_id');

        $merchantNames = empty($topMerchantIds) ? collect() : Merchant::query()->whereIn('id', $topMerchantIds)->pluck('name', 'id');

        $extract = function ($dateKey, string $field) use ($rows) {
            $byHour = ($rows->get($dateKey) ?? collect())->keyBy('h');
            $out = [];
            for ($h = 0; $h < 24; $h++) {
                $out[] = (int) ($byHour->get($h)->{$field} ?? 0);
            }

            return $out;
        };

        $extractWithdrawal = function ($dateKey, string $field = 'withdrawal') use ($withdrawalRows) {
            $byHour = ($withdrawalRows->get($dateKey) ?? collect())->keyBy('h');
            $out = [];
            for ($h = 0; $h < 24; $h++) {
                $out[] = (int) ($byHour->get($h)->{$field} ?? 0);
            }

            return $out;
        };

        $extractSeries = function ($groupedRows, $keys) {
            $series = [];
            foreach ($keys as $key => $name) {
                $byHour = ($groupedRows->get($key) ?? collect())->keyBy('h');
                $data = [];
                for ($h = 0; $h < 24; $h++) {
                    $data[] = (int) ($byHour->get($h)->volume ?? 0);
                }
                $series[] = ['name' => $name, 'data' => $data];
            }

            return $series;
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
            'todayWithdrawalPending' => $extractWithdrawal($today, 'withdrawal_pending'),
            'qrisSourceSeries' => $extractSeries($sourceRows, array_combine($topChannels, $topChannels)),
            'topMerchantSeries' => $extractSeries($merchantRows, $merchantNames->all()),
            'currentHour' => (int) now('Asia/Jakarta')->format('G'),
        ];
    }

    /**
     * 30-day daily breakdown per channel/merchant, for the Analytics card's
     * 7D/Month range toggle. Split out from overviewHourlyFlow() (and cached
     * separately with a longer TTL, see page()) because this GROUP BY over
     * 30 days x channel/merchant is the most expensive query on the Overview
     * page on a large merchant portfolio - no need to re-pay that cost every
     * time the cheap, fast-changing hourly cache expires.
     */
    private function overviewDailyBreakdown($merchantIds, array $topChannels = [], array $topMerchantIds = []): array
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $dateExpr = $isSqlite ? "date(submitted_at, '+7 hours')" : "DATE(CONVERT_TZ(submitted_at, '+00:00', '+07:00'))";

        $todayStart = now('Asia/Jakarta')->startOfDay();
        $since30 = $todayStart->copy()->subDays(29);

        $dailySourceRows = empty($topChannels) ? collect() : TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->whereIn('channel', $topChannels)
            ->where('status', 'success')
            ->where('submitted_at', '>=', $since30->copy()->utc())
            ->where('submitted_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw("{$dateExpr} as d, channel")
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('d', 'channel')
            ->get()
            ->groupBy('channel');

        $dailyMerchantRows = empty($topMerchantIds) ? collect() : TopupRequest::query()
            ->whereIn('merchant_id', $topMerchantIds)
            ->where('status', 'success')
            ->where('submitted_at', '>=', $since30->copy()->utc())
            ->where('submitted_at', '<', $todayStart->copy()->addDay()->utc())
            ->selectRaw("{$dateExpr} as d, merchant_id")
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('d', 'merchant_id')
            ->get()
            ->groupBy('merchant_id');

        $merchantNames = empty($topMerchantIds) ? collect() : Merchant::query()->whereIn('id', $topMerchantIds)->pluck('name', 'id');

        $dailyLabels = [];
        for ($i = 29; $i >= 0; $i--) {
            $dailyLabels[] = $todayStart->copy()->subDays($i)->toDateString();
        }

        $extractDailySeries = function ($groupedRows, $keys) use ($dailyLabels) {
            $series = [];
            foreach ($keys as $key => $name) {
                $byDate = ($groupedRows->get($key) ?? collect())->keyBy('d');
                $data = [];
                foreach ($dailyLabels as $d) {
                    $data[] = (int) ($byDate->get($d)->volume ?? 0);
                }
                $series[] = ['name' => $name, 'data' => $data];
            }

            return $series;
        };

        return [
            'dailyLabels' => $dailyLabels,
            'qrisSourceDailySeries' => $extractDailySeries($dailySourceRows, array_combine($topChannels, $topChannels)),
            'topMerchantDailySeries' => $extractDailySeries($dailyMerchantRows, $merchantNames->all()),
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
            'revenue_today' => 'volume_success',
            'successful_volume' => 'withdrawal',
            'success_rate' => 'success_rate',
            'pending' => 'pending_amount',
            'at_risk' => 'risk_amount',
        ];
        // Only the two stat tiles ('revenue_today'/Collection, 'successful_volume'/
        // Disbursement) show a transaction count, each from its own domain -
        // collection trx from TopupRequest, disbursement trx from MerchantWithdrawal.
        $trxFields = [
            'revenue_today' => 'trx_success',
            'successful_volume' => 'withdrawal_trx',
        ];

        return collect($metrics)->map(fn ($field, $key) => [
            'value' => $today[$field],
            'change' => $pctChange($today[$field], $yesterday[$field] ?? 0),
            'spark' => $spark($field),
            'trx' => isset($trxFields[$key]) ? ($today[$trxFields[$key]] ?? 0) : 0,
        ])->all();
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
     * All QRIS payment sources (bank/channel) by today's successful TRX
     * count and amount, for the Overview "QRIS Source" panel - replaces the
     * old per-transaction live ticker with an aggregated daily ranking.
     * Unlimited (the view paginates client-side) so every channel shows up,
     * not just the top performers.
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
            ->get();
    }

    /**
     * All banks by withdrawal amount today, for the Overview "Withdrawal by
     * Bank" panels - both completed and pending are today-scoped (matches
     * the rest of this page's daily framing). Unlimited (the view paginates
     * client-side) so every bank shows up, not just the top performers.
     */
    private function withdrawalsByBank($merchantIds, string $status): \Illuminate\Support\Collection
    {
        $todayStart = now('Asia/Jakarta')->startOfDay();

        $query = MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->where('status', $status)
            ->whereNotNull('bank_name')
            ->where('bank_name', '!=', '')
            ->where('gateway_created_at', '>=', $todayStart->copy()->utc())
            ->where('gateway_created_at', '<', $todayStart->copy()->addDay()->utc());

        return $query->selectRaw('bank_name, COUNT(*) as trx_total')
            ->selectRaw('COALESCE(SUM(amount), 0) as volume')
            ->groupBy('bank_name')
            ->orderByDesc('volume')
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

    /**
     * Demo accounts must never see real, unassigned (agent_id-null)
     * registrations or other MAs' data that the normal "any MA can claim
     * an unassigned registration" business rule would otherwise expose.
     */
    private function isDemoUser(): bool
    {
        return (bool) request()->user()?->is_demo;
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
