<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GatewayBalanceService;
use App\Services\Navigation\MenuBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class MerchantAdminController extends Controller
{
    public function page(Merchant $merchant, string $page, MenuBuilder $menus): View
    {
        $menu = $menus->merchantAdmin($merchant);
        abort_if(! collect($menu)->contains('key', $page), 404);

        $users = User::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('role', ['admin', 'finance', 'cs', 'readonly_admin', 'readonly_cs'])
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $from = request('from');
        $to = request('to');
        if (in_array($page, ['qris', 'checklist'], true)) {
            $from = $from ?: now('Asia/Jakarta')->toDateString();
            $to = $to ?: $from;
        }
        $search = trim((string) request('q', ''));
        $status = (string) request('status', 'all');
        $processed = (string) request('processed', 'all');
        $requests = TopupRequest::query()
            ->with('ticket')
            ->where('merchant_id', $merchant->id)
            ->when($from && $to, fn ($query) => $query->whereDate('submitted_at', '>=', $from)->whereDate('submitted_at', '<=', $to))
            ->when($page === 'checklist', fn ($query) => $query->where('status', 'success'))
            ->when($status !== 'all' && $page !== 'checklist', fn ($query) => $status === 'expired' ? $query->whereIn('status', ['expired', 'failed', 'rejected']) : $query->where('status', $status))
            ->when($processed === 'checked', fn ($query) => $query->where('is_processed', true))
            ->when($processed === 'unchecked', fn ($query) => $query->where('is_processed', false))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('payment_id', 'like', "{$search}%")
                    ->orWhere('transaction_id', 'like', "{$search}%")
                    ->orWhere('gateway_ref_id', 'like', "{$search}%")
                    ->orWhere('rrn', 'like', "{$search}%")
                    ->orWhere('customer_reference', 'like', "{$search}%");
            }))
            ->orderByRaw("CASE WHEN status = 'success' AND is_processed = 0 THEN 0 WHEN status = 'success' AND is_processed = 1 THEN 1 WHEN status = 'pending' THEN 2 WHEN status IN ('expired', 'failed', 'rejected') THEN 3 ELSE 4 END")
            ->latest('submitted_at')
            ->simplePaginate(config('paygrid.reports.default_page_size', 50))
            ->withQueryString();
        $stats = $this->transactionStats($merchant, $from, $to, $search);

        $logs = $this->logs($merchant);
        $topupLogTargets = TopupRequest::query()
            ->whereIn('id', $logs->where('target_type', TopupRequest::class)->pluck('target_id')->filter()->map(fn ($id) => (int) $id))
            ->get()
            ->keyBy('id');

        return view('paygrid.merchant-admin', [
            'roleLabel' => $merchant->name.' Admin',
            'merchant' => $merchant,
            'menus' => $menu,
            'active' => $page,
            'title' => match ($page) {
                'settings' => 'Atur Minimum Topup',
                'logs' => 'Log Aktivitas',
                'qris' => 'Topup Request',
                'checklist' => 'Sukses Checklist',
                'history' => 'History TRX',
                default => 'Data User',
            },
            'users' => $users,
            'logs' => $logs,
            'topupLogTargets' => $topupLogTargets,
            'requests' => $requests,
            'stats' => $stats,
            'topupCards' => $this->topupCards($stats),
            'gatewayBalance' => in_array($page, ['qris', 'checklist', 'history'], true) ? app(GatewayBalanceService::class)->current($merchant) : ['active' => 0, 'pending' => 0],
            'latestSync' => GatewaySyncLog::query()->where('merchant_id', $merchant->id)->where('direction', 'pull')->latest('finished_at')->first(),
            'from' => $from,
            'to' => $to,
            'search' => $search,
            'status' => $status,
            'processed' => $processed,
        ]);
    }

    public function storeUser(Request $request, Merchant $merchant, AuditLogService $audit): RedirectResponse
    {
        $this->authorizeUserManagement($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'role' => ['required', 'in:admin,finance,cs'],
            'password' => ['required', 'string', 'min:6', 'max:120'],
        ]);
        $name = str($data['email'])->before('@')->replace(['.', '_', '-'], ' ')->title()->toString();

        $user = User::query()->create([
            'name' => $name,
            'email' => $data['email'],
            'role' => $data['role'],
            'merchant_id' => $merchant->id,
            'password' => Hash::make($data['password']),
            'plain_password' => $data['password'],
        ]);
        $audit->record('admin.user_created', $user, null, $user->only(['name', 'email', 'role', 'merchant_id']));

        return back()->with('status', 'User berhasil dibuat.');
    }

    public function resetPassword(Request $request, Merchant $merchant, User $user, AuditLogService $audit): RedirectResponse
    {
        $this->authorizeUserManagement($request);
        abort_unless((int) $user->merchant_id === (int) $merchant->id && in_array($user->role, ['admin', 'finance', 'cs', 'readonly_admin', 'readonly_cs'], true), 403);
        $data = $request->validate(['password' => ['required', 'string', 'min:6', 'max:120']]);
        $before = $user->only(['id', 'email', 'role']);
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'plain_password' => $data['password'],
        ])->save();
        $audit->record('admin.user_password_reset', $user, $before, $user->only(['id', 'email', 'role']));

        return back()->with('status', 'Password user berhasil direset.');
    }

    private function authorizeUserManagement(Request $request): void
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'ma', 'superadmin'], true), 403);
    }

    public function updateMinimumTopup(Request $request, Merchant $merchant, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'minimum_topup_amount' => ['required', 'integer', 'min:1000'],
        ]);
        $before = $merchant->only(['minimum_topup_amount']);
        $merchant->forceFill(['minimum_topup_amount' => $data['minimum_topup_amount']])->save();
        $audit->record('admin.minimum_topup_updated', $merchant, $before, $merchant->only(['minimum_topup_amount']));

        return back()->with('status', 'Minimum topup berhasil disimpan.');
    }

    private function logs(Merchant $merchant)
    {
        $userIds = User::query()->where('merchant_id', $merchant->id)->select('id');
        $requestIds = TopupRequest::query()->where('merchant_id', $merchant->id)->select('id');

        return AuditLog::query()
            ->with('actor')
            ->where('action', 'topup.checklist_marked')
            ->where(function ($query) use ($merchant, $userIds, $requestIds) {
                $query->whereIn('actor_user_id', $userIds)
                    ->orWhere(fn ($nested) => $nested->where('target_type', TopupRequest::class)->whereIn('target_id', $requestIds))
                    ->orWhere(fn ($nested) => $nested->where('target_type', Merchant::class)->where('target_id', (string) $merchant->id));
            })
            ->latest('created_at')
            ->limit(150)
            ->get();
    }

    private function transactionStats(Merchant $merchant, ?string $from, ?string $to, string $search): array
    {
        $row = TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->when($from && $to, fn ($query) => $query->whereDate('submitted_at', '>=', $from)->whereDate('submitted_at', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('payment_id', 'like', "{$search}%")
                    ->orWhere('gateway_ref_id', 'like', "{$search}%")
                    ->orWhere('transaction_id', 'like', "{$search}%")
                    ->orWhere('rrn', 'like', "{$search}%")
                    ->orWhere('customer_reference', 'like', "{$search}%");
            }))
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as total")
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as expired")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN amount ELSE 0 END), 0) as expired_amount")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND is_processed = 1 THEN 1 ELSE 0 END) as success_checked_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' AND is_processed = 1 THEN amount ELSE 0 END), 0) as success_checked_amount")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND is_processed = 0 THEN 1 ELSE 0 END) as success_unchecked_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' AND is_processed = 0 THEN amount ELSE 0 END), 0) as success_unchecked_amount")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'success' => (int) ($row->success ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'volume_success' => (int) ($row->volume_success ?? 0),
            'pending_amount' => (int) ($row->pending_amount ?? 0),
            'expired_amount' => (int) ($row->expired_amount ?? 0),
            'success_checked_count' => (int) ($row->success_checked_count ?? 0),
            'success_checked_amount' => (int) ($row->success_checked_amount ?? 0),
            'success_unchecked_count' => (int) ($row->success_unchecked_count ?? 0),
            'success_unchecked_amount' => (int) ($row->success_unchecked_amount ?? 0),
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
}
