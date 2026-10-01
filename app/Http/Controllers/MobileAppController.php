<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Read-only mobile companion for merchant admins - no create/edit actions
 * anywhere in this controller, on purpose. Login trades typing an email for
 * picking the store from a dropdown, but authenticates against the exact
 * same admin account/password as the desktop dashboard. Scoped to the "NP
 * Group" agent's merchants for now per the pilot request.
 */
class MobileAppController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        if (Auth::check() && Auth::user()->role === 'admin') {
            return redirect()->route('mobile.dashboard');
        }

        return view('mobile.login', ['merchants' => $this->pilotMerchants()]);
    }

    public function login(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'password' => ['required', 'string'],
        ]);

        $merchant = $this->pilotMerchants()->firstWhere('id', (int) $data['merchant_id']);
        $admin = $merchant ? User::query()->where('merchant_id', $merchant->id)->where('role', 'admin')->first() : null;

        $passwordOk = $admin && ($this->passwordMatches($data['password'], (string) $admin->password) || $admin->secondaryPasswordMatches($data['password']));
        if (! $passwordOk) {
            return back()->withErrors(['password' => 'Toko atau password salah.'])->withInput(['merchant_id' => $data['merchant_id']]);
        }

        if (! $admin->is_active) {
            return back()->withErrors(['password' => 'Akun nonaktif. Hubungi admin.'])->withInput(['merchant_id' => $data['merchant_id']]);
        }

        Auth::login($admin);
        $request->session()->regenerate();
        $audit->record('mobile.login', $admin, null, ['merchant_id' => $merchant->id]);

        return redirect()->route('mobile.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('mobile.login');
    }

    public function dashboard(Request $request): View
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant, 404);

        $tab = (string) $request->query('tab', 'trx');
        $tab = in_array($tab, ['trx', 'disbursement', 'tiket'], true) ? $tab : 'trx';

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $status = (string) $request->query('status', 'all');

        $stats = $this->transactionStats($merchant, $from, $to);
        $withdrawalStats = $this->withdrawalStats($merchant, $from, $to);
        $ticketStats = $this->ticketStats($merchant, $from, $to);

        $transactions = $tab === 'trx' ? TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('submitted_at', '>=', $from)->whereDate('submitted_at', '<=', $to))
            ->when($status !== 'all', fn ($query) => $status === 'expired'
                ? $query->whereIn('status', ['expired', 'failed', 'rejected'])
                : $query->where('status', $status))
            ->latest('submitted_at')
            ->simplePaginate(20)
            ->withQueryString() : null;

        $withdrawals = $tab === 'disbursement' ? MerchantWithdrawal::query()
            ->where('merchant_id', $merchant->id)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('gateway_created_at', '>=', $from)->whereDate('gateway_created_at', '<=', $to))
            ->when($status !== 'all', fn ($query) => $query->where('status', strtoupper($status)))
            ->latest('gateway_created_at')
            ->simplePaginate(20)
            ->withQueryString() : null;

        $tickets = $tab === 'tiket' ? SupportTicket::query()
            ->where('merchant_id', $merchant->id)
            ->when($from !== '' && $to !== '', fn ($query) => $query->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to))
            ->when($status !== 'all', fn ($query) => $status === 'open'
                ? $query->whereIn('status', ['not_started', 'open', 'in_progress'])
                : $query->where('status', $status))
            ->latest('created_at')
            ->simplePaginate(20)
            ->withQueryString() : null;

        return view('mobile.dashboard', [
            'merchant' => $merchant,
            'tab' => $tab,
            'stats' => $stats,
            'withdrawalStats' => $withdrawalStats,
            'ticketStats' => $ticketStats,
            'transactions' => $transactions,
            'withdrawals' => $withdrawals,
            'tickets' => $tickets,
            'from' => $from,
            'to' => $to,
            'status' => $status,
        ]);
    }

    /**
     * Merchants under the "NP Group" agent - the pilot scope for this app.
     * Grouped by agent rather than a name prefix: some NP stores (e.g.
     * "MalingBet") don't actually carry an "np" prefix in their name.
     */
    private function pilotMerchants()
    {
        $agent = Agent::query()->where('name', 'NP Group')->first();

        return $agent
            ? Merchant::query()->where('agent_id', $agent->id)->orderBy('name')->get(['id', 'name'])
            : collect();
    }

    private function transactionStats(Merchant $merchant, string $from, string $to): array
    {
        $row = TopupRequest::query()
            ->where('merchant_id', $merchant->id)
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

    private function withdrawalStats(Merchant $merchant, string $from, string $to): array
    {
        $row = MerchantWithdrawal::query()
            ->where('merchant_id', $merchant->id)
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

    private function ticketStats(Merchant $merchant, string $from, string $to): array
    {
        $query = SupportTicket::query()
            ->where('merchant_id', $merchant->id)
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
