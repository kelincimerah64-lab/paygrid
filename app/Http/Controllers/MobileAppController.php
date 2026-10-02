<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GatewayBalanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Read-only mobile companion - no create/edit actions anywhere in this
 * controller, on purpose. Login is a single email/username + password form,
 * exactly like the desktop dashboard - the role on the matched account
 * decides what the dashboard shows, same as desktop's AuthController.
 */
class MobileAppController extends Controller
{
    /** Which mobile tabs each merchant-tier role is allowed to see. */
    private const MERCHANT_ROLE_TABS = [
        'admin' => ['trx', 'disbursement', 'tiket'],
        'boss' => ['trx', 'disbursement', 'tiket'],
        'finance' => ['keuangan', 'disbursement'],
        'cs' => ['tiket', 'disbursement'],
    ];

    public function loginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('mobile.dashboard');
        }

        return view('mobile.login');
    }

    public function login(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = Str::lower($data['email']);
        $candidates = User::query()
            ->whereRaw('LOWER(email) = ?', [$login])
            ->orWhereRaw('LOWER(username) = ?', [$login])
            ->get();
        $user = $candidates->first(fn (User $candidate): bool => $this->passwordMatches($data['password'], (string) $candidate->password)
            || $candidate->secondaryPasswordMatches($data['password']));

        if (! $user) {
            return back()->withErrors(['email' => 'Email/username atau password salah.'])->withInput(['email' => $data['email']]);
        }

        if (! $user->is_active) {
            return back()->withErrors(['email' => 'Akun nonaktif. Hubungi admin.'])->withInput(['email' => $data['email']]);
        }

        if ($this->resolvePortfolio($user) === null) {
            return back()->withErrors(['email' => 'Akun ini belum didukung di aplikasi mobile.'])->withInput(['email' => $data['email']]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $audit->record('mobile.login', $user, null, ['role' => $user->role]);

        return redirect()->route('mobile.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('mobile.login');
    }

    public function dashboard(Request $request, GatewayBalanceService $balances): View
    {
        $portfolio = $this->resolvePortfolio($request->user());
        abort_if($portfolio === null, 404);

        $merchantIds = $portfolio['merchantIds'];
        $allowedTabs = $portfolio['tabs'];

        $tab = (string) $request->query('tab', $allowedTabs[0]);
        $tab = in_array($tab, $allowedTabs, true) ? $tab : $allowedTabs[0];

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $status = (string) $request->query('status', 'all');

        $needsTrxStats = array_intersect(['trx', 'keuangan'], $allowedTabs) !== [];
        $stats = $needsTrxStats ? $this->transactionStats($merchantIds, $from, $to) : null;
        $withdrawalStats = in_array('disbursement', $allowedTabs, true) ? $this->withdrawalStats($merchantIds, $from, $to) : null;
        $ticketStats = in_array('tiket', $allowedTabs, true) ? $this->ticketStats($merchantIds, $from, $to) : null;

        $transactions = $tab === 'trx'
            ? $this->transactionsQuery($merchantIds, $from, $to, $status)->simplePaginate(20)->withQueryString()
            : null;

        $withdrawals = $tab === 'disbursement' ? $this->withdrawalsQuery($merchantIds, $from, $to, $status)->simplePaginate(20)->withQueryString() : null;

        $tickets = $tab === 'tiket' ? $this->ticketsQuery($merchantIds, $from, $to, $status)->simplePaginate(20)->withQueryString() : null;

        $saldo = $tab === 'keuangan' && $portfolio['merchant'] ? $balances->current($portfolio['merchant']) : null;

        return view('mobile.dashboard', [
            'identity' => $portfolio['identity'],
            'merchant' => $portfolio['merchant'],
            'tab' => $tab,
            'allowedTabs' => $allowedTabs,
            'stats' => $stats,
            'withdrawalStats' => $withdrawalStats,
            'ticketStats' => $ticketStats,
            'saldo' => $saldo,
            'transactions' => $transactions,
            'withdrawals' => $withdrawals,
            'tickets' => $tickets,
            'from' => $from,
            'to' => $to,
            'status' => $status,
        ]);
    }

    /**
     * Resolves which merchants, tabs, and display identity a logged-in
     * mobile user gets. Returns null for any role this app doesn't support,
     * or for a portfolio role that can't be resolved to an actual portfolio
     * (e.g. an 'agent' user with no matching Agent record).
     *
     * @return array{merchantIds: Collection<int, int>, merchant: ?Merchant, identity: string, tabs: array<int, string>}|null
     */
    private function resolvePortfolio(User $user): ?array
    {
        if (array_key_exists($user->role, self::MERCHANT_ROLE_TABS)) {
            $merchant = $user->merchant;
            if (! $merchant) {
                return null;
            }

            return [
                'merchantIds' => collect([$merchant->id]),
                'merchant' => $merchant,
                'identity' => $merchant->name,
                'tabs' => self::MERCHANT_ROLE_TABS[$user->role],
            ];
        }

        if ($user->role === 'ma') {
            return [
                'merchantIds' => Merchant::query()->whereRelation('agent', 'ma_user_id', $user->id)->pluck('id'),
                'merchant' => null,
                'identity' => 'MA - '.$user->name,
                'tabs' => ['trx', 'disbursement', 'tiket'],
            ];
        }

        if ($user->role === 'agent') {
            $agent = Agent::query()->where('code', $user->username)->orWhere('email', $user->email)->first();
            if (! $agent) {
                return null;
            }

            return [
                'merchantIds' => Merchant::query()->where('agent_id', $agent->id)->pluck('id'),
                'merchant' => null,
                'identity' => 'Agen - '.$agent->name,
                'tabs' => ['trx', 'disbursement', 'tiket'],
            ];
        }

        return null;
    }

    private function transactionsQuery(Collection $merchantIds, string $from, string $to, string $status): Builder
    {
        return TopupRequest::query()
            ->with('merchant:id,name')
            ->whereIn('merchant_id', $merchantIds)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('submitted_at', '>=', $from)->whereDate('submitted_at', '<=', $to))
            ->when($status !== 'all', fn ($query) => $status === 'expired'
                ? $query->whereIn('status', ['expired', 'failed', 'rejected'])
                : $query->where('status', $status))
            ->latest('submitted_at');
    }

    private function withdrawalsQuery(Collection $merchantIds, string $from, string $to, string $status): Builder
    {
        return MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('gateway_created_at', '>=', $from)->whereDate('gateway_created_at', '<=', $to))
            ->when($status !== 'all', fn ($query) => $query->where('status', strtoupper($status)))
            ->latest('gateway_created_at');
    }

    private function ticketsQuery(Collection $merchantIds, string $from, string $to, string $status): Builder
    {
        return SupportTicket::query()
            ->whereIn('merchant_id', $merchantIds)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to))
            ->when($status !== 'all', fn ($query) => $status === 'open'
                ? $query->whereIn('status', ['not_started', 'open', 'in_progress'])
                : $query->where('status', $status))
            ->latest('created_at');
    }

    private function transactionStats(Collection $merchantIds, string $from, string $to): array
    {
        $row = TopupRequest::query()
            ->whereIn('merchant_id', $merchantIds)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('submitted_at', '>=', $from)->whereDate('submitted_at', '<=', $to))
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as success_amount")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->selectRaw("SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as expired")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN amount ELSE 0 END), 0) as expired_amount")
            ->first();

        return [
            'success' => (int) ($row->success ?? 0),
            'success_amount' => (int) ($row->success_amount ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'pending_amount' => (int) ($row->pending_amount ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'expired_amount' => (int) ($row->expired_amount ?? 0),
        ];
    }

    private function withdrawalStats(Collection $merchantIds, string $from, string $to): array
    {
        $row = MerchantWithdrawal::query()
            ->whereIn('merchant_id', $merchantIds)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('gateway_created_at', '>=', $from)->whereDate('gateway_created_at', '<=', $to))
            ->selectRaw("SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN amount ELSE 0 END), 0) as completed_amount")
            ->selectRaw("SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'PENDING' THEN amount ELSE 0 END), 0) as pending_amount")
            ->selectRaw("SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'FAILED' THEN amount ELSE 0 END), 0) as failed_amount")
            ->first();

        return [
            'completed' => (int) ($row->completed ?? 0),
            'completed_amount' => (int) ($row->completed_amount ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'pending_amount' => (int) ($row->pending_amount ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'failed_amount' => (int) ($row->failed_amount ?? 0),
        ];
    }

    private function ticketStats(Collection $merchantIds, string $from, string $to): array
    {
        $query = SupportTicket::query()
            ->whereIn('merchant_id', $merchantIds)
            ->when($from !== '' && $to !== '', fn ($q) => $q->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to));

        return [
            'total' => (clone $query)->count(),
            'open' => (clone $query)->whereIn('status', ['not_started', 'open', 'in_progress'])->count(),
            'done' => (clone $query)->where('status', 'done')->count(),
        ];
    }

    private function passwordMatches(string $plain, string $hash): bool
    {
        try {
            return Hash::check($plain, $hash);
        } catch (\RuntimeException) {
            return password_verify($plain, $hash);
        }
    }
}
