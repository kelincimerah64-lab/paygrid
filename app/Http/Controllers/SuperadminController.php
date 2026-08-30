<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\PaygridSetting;
use App\Models\TopupRequest;
use App\Models\User;
use App\Rules\ExactlyOneFeeMenuFilled;
use App\Rules\FeeMenuRatesAboveFloor;
use App\Services\AuditLogService;
use App\Services\FeeMenuCatalog;
use App\Services\FeeSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SuperadminController extends Controller
{
    public function page(FeeMenuCatalog $feeMenus, string $page = 'dashboard-fee'): View
    {
        abort_unless(in_array($page, ['dashboard-fee', 'add-fee', 'ma', 'merchant-group', 'timer-ticket', 'accounts'], true), 404);

        $search = trim((string) request('q', ''));
        $type = (string) request('type', 'all');
        $status = (string) request('status', 'all');
        $role = (string) request('role', 'all');
        $maId = (string) request('ma_id', 'all');
        $agentId = (string) request('agent_id', 'all');

        $merchants = Merchant::query()
            ->with('agent.ma')
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('name', 'like', "{$search}%")
                    ->orWhere('slug', 'like', "{$search}%")
                    ->orWhere('merchant_id', 'like', "{$search}%")
                    ->orWhereRelation('agent', 'name', 'like', "{$search}%");
            }))
            ->when($type !== 'all', fn ($query) => $query->where('merchant_type', $type))
            ->when($agentId !== 'all', fn ($query) => $query->where('agent_id', $agentId))
            ->orderBy('name')
            ->get();
        $mas = User::query()
            ->where('role', 'ma')
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', "{$search}%")->orWhere('email', 'like', "{$search}%")))
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->get();
        $agents = Agent::query()
            ->with('ma')
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', "{$search}%")->orWhere('code', 'like', "{$search}%")->orWhere('email', 'like', "{$search}%")))
            ->when($maId !== 'all', fn ($query) => $query->where('ma_user_id', $maId))
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->get();
        $users = User::query()
            ->with('merchant')
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', "{$search}%")->orWhere('email', 'like', "{$search}%")->orWhere('username', 'like', "{$search}%")))
            ->when($role !== 'all', fn ($query) => $query->where('role', $role))
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return view('paygrid.superadmin', [
            'roleLabel' => 'Superadmin',
            'menus' => $this->menus(),
            'active' => $page,
            'title' => match ($page) {
                'add-fee' => 'Add Fee',
                'ma' => 'MA',
                'merchant-group' => 'Merchant Group',
                'timer-ticket' => 'Timer Ticket',
                'accounts' => 'Daftar Account',
                default => 'Dashboard Fee',
            },
            'merchants' => $merchants,
            'mas' => $mas,
            'agents' => $agents,
            'users' => $users,
            'timerMinutes' => (int) PaygridSetting::value('ticket_pending_minutes', '40'),
            'summary' => $this->summary(),
            'filters' => compact('search', 'type', 'status', 'role', 'maId', 'agentId'),
            'feeMenus' => $feeMenus,
        ]);
    }

    public function storeMa(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $this->validateMa($request);
        $password = $data['password'];
        unset($data['password']);

        $user = User::query()->create($data + [
            'role' => 'ma',
            'password' => Hash::make($password),
            'plain_password' => $password,
        ]);
        $audit->record('superadmin.ma_created', $user, null, $user->only(['name', 'email', 'contact', 'is_active']));

        return back()->with('status', 'MA berhasil dibuat.');
    }

    public function updateMa(Request $request, User $user, AuditLogService $audit, FeeSyncService $feeSync): RedirectResponse
    {
        abort_unless($user->role === 'ma', 404);
        $data = $this->validateMa($request, $user);
        $password = $data['password'] ?? null;
        unset($data['password']);
        $before = $user->only(array_keys($data));

        if ($password) {
            $data['password'] = Hash::make($password);
            $data['plain_password'] = $password;
        }

        $user->forceFill($data)->save();
        $feeSync->resyncMa($user);
        $audit->record('superadmin.ma_updated', $user, $before, $user->only(array_keys($before)));

        return back()->with('status', 'MA berhasil diupdate.');
    }

    public function updateMerchantFee(Request $request, Merchant $merchant, AuditLogService $audit, FeeMenuCatalog $feeMenus, FeeSyncService $feeSync): RedirectResponse
    {
        $rates = $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'merchant');
        $request->merge(['fee_menu_rates' => $rates]);
        $data = $request->validate([
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('merchant', null), new ExactlyOneFeeMenuFilled()],
        ]);
        $data['fee_menu'] = array_key_first(array_filter($rates));
        $data['settlement_method'] = $feeMenus->settlementMethod($data['fee_menu']);
        $agent = $merchant->agent()->with('ma')->firstOrFail();
        $data = array_merge($data, $feeSync->snapshotFor($agent, $data['fee_menu'], $rates[$data['fee_menu']]));
        $before = $merchant->only(array_keys($data));
        $merchant->forceFill($data)->save();
        $audit->record('superadmin.merchant_fee_updated', $merchant, $before, $merchant->only(array_keys($data)));

        return back()->with('status', 'Fee toko berhasil disimpan. MDR Toko: '.$this->fmt($data['merchant_mdr_percent']).'%.');
    }

    public function storeAgent(Request $request, AuditLogService $audit, FeeMenuCatalog $feeMenus): RedirectResponse
    {
        $request->merge(['connection_type' => $request->input('connection_type', 'cm')]);
        $typeCategory = $feeMenus->typeCategory((string) $request->input('connection_type'));
        $request->merge(['fee_menu_rates' => $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'agent')]);
        $data = $request->validate([
            'ma_user_id' => ['nullable', 'exists:users,id'],
            'code' => ['nullable', 'string', 'max:40', 'unique:agents,code'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'contact' => ['nullable', 'string', 'max:80'],
            'connection_type' => ['required', 'in:cm,script'],
            'engine_type' => [Rule::requiredIf($typeCategory === 'engine'), 'nullable', 'in:sc,api'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('agent', null)],
            'is_active' => ['required', 'boolean'],
        ]);
        abort_if(config('paygrid.gateway.hilogate.agent_create_enabled'), 423, 'Create merchant group ke HG masih dinonaktifkan.');
        $data['code'] = ($data['code'] ?? null) ?: $this->uniqueAgentCode($data['name']);
        $data['hg_group_id'] = null;
        $password = config('paygrid.demo_password');
        $agent = Agent::query()->create($data);
        $agentUser = User::query()->updateOrCreate(
            ['username' => $agent->code],
            [
                'name' => $agent->name,
                'email' => $agent->email ?: Str::lower($agent->code).'@paygrid.local',
                'role' => 'agent',
                'is_active' => (bool) $agent->is_active,
                'password' => Hash::make($password),
                'plain_password' => $password,
            ],
        );
        $audit->record('superadmin.agent_created', $agent, null, $agent->toArray());
        $audit->record('superadmin.agent_account_created', $agentUser, null, $agentUser->only(['name', 'email', 'username', 'role']));

        return back()->with('status', 'Merchant Group lokal berhasil dibuat. Kode login: '.$agent->code.'. Password: '.$password.'. Belum dikirim ke HG.');
    }

    public function updateTimer(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate(['ticket_pending_minutes' => ['required', 'integer', 'min:1', 'max:1440']]);
        $setting = PaygridSetting::query()->updateOrCreate(
            ['key' => 'ticket_pending_minutes'],
            ['value' => (string) $data['ticket_pending_minutes']],
        );
        $audit->record('superadmin.timer_ticket_updated', $setting, null, ['ticket_pending_minutes' => $data['ticket_pending_minutes']]);

        return back()->with('status', 'Timer ticket berhasil disimpan.');
    }

    public function resetAccount(Request $request, User $user, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate(['password' => ['nullable', 'string', 'min:6', 'max:120']]);
        $password = $data['password'] ?: Str::password(10, true, true, false, false);
        $before = $user->only(['id', 'email', 'role']);
        $user->resetCredentials($password);
        $user->forceFill(['is_active' => true])->save();
        $audit->record('superadmin.account_reset', $user, $before, $user->only(['id', 'email', 'role', 'is_active']));

        return back()->with('status', 'Akses user berhasil direset. Password: '.$password);
    }

    private function validateMa(Request $request, ?User $user = null): array
    {
        $feeMenus = app(FeeMenuCatalog::class);
        $request->merge(['fee_menu_rates' => $feeMenus->normalizeRates((array) $request->input('fee_menu_rates', []), 'ma')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'.($user ? ','.$user->id : '')],
            'contact' => ['nullable', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:6', 'max:120'],
            'fee_menu_rates' => [new FeeMenuRatesAboveFloor('ma', null)],
        ]);
    }

    private function summary(): array
    {
        $row = TopupRequest::query()
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as total")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as success_volume")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN fee_amount ELSE 0 END), 0) as fee_total")
            ->first();

        return [
            'total' => (int) $row->total,
            'success_volume' => (int) $row->success_volume,
            'fee_total' => (int) $row->fee_total,
        ];
    }

    private function menus(): array
    {
        return [
            ['key' => 'dashboard-fee', 'label' => 'Dashboard Fee', 'url' => route('superadmin.overview')],
            ['key' => 'add-fee', 'label' => 'Add Fee', 'url' => route('superadmin.page', 'add-fee')],
            ['key' => 'ma', 'label' => 'MA', 'url' => route('superadmin.page', 'ma')],
            ['key' => 'merchant-group', 'label' => 'Merchant Group', 'url' => route('superadmin.page', 'merchant-group')],
            ['key' => 'timer-ticket', 'label' => 'Timer Ticket', 'url' => route('superadmin.page', 'timer-ticket')],
            ['key' => 'accounts', 'label' => 'Daftar Account', 'url' => route('superadmin.page', 'accounts')],
        ];
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function uniqueAgentCode(string $name): string
    {
        $base = 'AG-'.Str::upper(Str::slug($name, '-'));
        $base = Str::limit($base ?: 'AG-GROUP', 32, '');
        $code = $base;
        $suffix = 2;

        while (Agent::query()->where('code', $code)->exists()) {
            $code = Str::limit($base, 28, '').'-'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
