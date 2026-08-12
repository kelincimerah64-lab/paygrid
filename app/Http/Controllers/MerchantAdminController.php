<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\AuditLogService;
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
            ->whereIn('role', ['admin', 'finance', 'cs'])
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $rangeStart = request('from') ? CarbonImmutable::parse(request('from'), 'Asia/Jakarta')->startOfDay() : null;
        $rangeEnd = request('to') ? CarbonImmutable::parse(request('to'), 'Asia/Jakarta')->endOfDay() : null;
        $search = trim((string) request('q', ''));
        $requests = TopupRequest::query()
            ->with('ticket')
            ->where('merchant_id', $merchant->id)
            ->when($rangeStart && $rangeEnd, fn ($query) => $query->whereBetween('submitted_at', [$rangeStart, $rangeEnd]))
            ->when($page === 'checklist', fn ($query) => $query->where('status', 'success'))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('payment_id', 'like', "{$search}%")
                    ->orWhere('transaction_id', 'like', "{$search}%")
                    ->orWhere('rrn', 'like', "{$search}%")
                    ->orWhere('customer_reference', 'like', "{$search}%");
            }))
            ->latest('submitted_at')
            ->simplePaginate(config('paygrid.reports.default_page_size', 50))
            ->withQueryString();

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
            'from' => request('from'),
            'to' => request('to'),
            'search' => $search,
        ]);
    }

    public function storeUser(Request $request, Merchant $merchant, AuditLogService $audit): RedirectResponse
    {
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
        abort_unless((int) $user->merchant_id === (int) $merchant->id && in_array($user->role, ['admin', 'finance', 'cs'], true), 403);
        $data = $request->validate(['password' => ['required', 'string', 'min:6', 'max:120']]);
        $before = $user->only(['id', 'email', 'role']);
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'plain_password' => $data['password'],
        ])->save();
        $audit->record('admin.user_password_reset', $user, $before, $user->only(['id', 'email', 'role']));

        return back()->with('status', 'Password user berhasil direset.');
    }

    public function updateMinimumTopup(Request $request, Merchant $merchant, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'minimum_topup_amount' => ['required', 'integer', 'min:1000', 'max:'.config('paygrid.topup.maximum_amount')],
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
}
