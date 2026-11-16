<?php

namespace App\Services\Navigation;

use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationReadService;
use Illuminate\Support\Facades\Cache;

class MenuBuilder
{
    public function admin(): array
    {
        return [
            ['key' => 'users', 'label' => 'Data User', 'url' => route('admin.users')],
            ['key' => 'logs', 'label' => 'Log Aktivitas', 'url' => route('admin.logs')],
            ['key' => 'monitoring', 'label' => 'Monitoring Gateway', 'url' => route('admin.monitoring')],
            ['key' => 'topup', 'label' => 'Top Up Request', 'url' => route('admin.users')],
            ['key' => 'checklist', 'label' => 'Sukses Checklist', 'url' => route('admin.users')],
            ['key' => 'finance', 'label' => 'Toko Finance', 'url' => route('admin.users')],
            ['key' => 'cs', 'label' => 'Toko CS', 'url' => route('admin.users')],
        ];
    }

    /**
     * $user defaults to the authenticated user so every existing ->ma() call
     * site keeps working unchanged - only pages that actually need a
     * different user (none currently) would pass one explicitly.
     */
    public function ma(?User $user = null): array
    {
        $user ??= auth()->user();
        $pendingApprovals = null;
        if ($user) {
            $reads = app(NotificationReadService::class);
            $maId = $user->role === 'ma' ? $user->id : null;
            $pendingApprovals = Cache::remember('ma:sidebar-badges:'.$user->id, 10, fn () => $reads->unseenCount(
                $user,
                'ma-approvals',
                \App\Models\MerchantRegistration::query()
                    ->whereNotIn('status', ['approved', 'rejected'])
                    ->when($maId, fn ($query) => $query->where(fn ($nested) => $nested
                        ->whereRelation('agent', 'ma_user_id', $maId)
                        ->when(! $user->is_demo, fn ($n) => $n->orWhereNull('agent_id')))),
                'created_at',
            ));
        }

        $menu = [
            ['key' => 'overview', 'label' => 'Overview', 'url' => route('ma.overview')],
            ['key' => 'report', 'label' => 'Report', 'url' => route('ma.report')],
            ['key' => 'fee', 'label' => 'Fee', 'url' => route('ma.fee')],
            ['key' => 'approval', 'label' => 'Request Approval', 'url' => route('ma.approvals'), 'badge' => $pendingApprovals ?: null],
            ['key' => 'mapping', 'label' => 'Mapping Agen', 'url' => route('ma.mapping')],
            ['key' => 'insentif', 'label' => 'Form Insentif', 'url' => route('ma.insentif')],
            ['key' => 'commission', 'label' => 'Komisi Toko', 'url' => route('ma.commission')],
            ['key' => 'commission-report', 'label' => 'Hasil Komisi', 'url' => route('ma.commission.report')],
            ['key' => 'stores', 'label' => 'List Toko', 'url' => route('ma.stores')],
            ['key' => 'agents', 'label' => 'Agen', 'url' => route('ma.agents')],
            ['key' => 'create-store', 'label' => 'Create Toko', 'url' => route('ma.create-store')],
            ['key' => 'create-store-magnetopay', 'label' => 'Create Toko (Magneto)', 'url' => route('ma.create-store-magnetopay')],
            ['key' => 'magnetopay-submissions', 'label' => 'Submission Magneto', 'url' => route('ma.magnetopay-submissions')],
            ['key' => 'bot-monitoring', 'label' => 'Monitoring Bot Telegram', 'url' => route('ma.bot-monitoring')],
            ['key' => 'analytics', 'label' => 'Analytics', 'url' => route('ma.analytics')],
            ['key' => 'monitor', 'label' => 'Monitor CS', 'url' => route('cs-scope.index')],
            ['key' => 'create-ticket', 'label' => 'Create Ticket', 'url' => route('ma.tickets.index')],
        ];

        if ($user?->role === 'magneto_ops') {
            $menu = array_values(array_filter($menu, fn ($item) => in_array($item['key'], ['create-store-magnetopay', 'magnetopay-submissions'], true)));
        } elseif ($user?->is_demo) {
            $menu = array_values(array_filter($menu, fn ($item) => ! in_array($item['key'], ['create-store-magnetopay', 'magnetopay-submissions', 'bot-monitoring'], true)));
        }

        return $menu;
    }

    public function deptStaff(): array
    {
        return [
            ['key' => 'tickets', 'label' => 'Tickets', 'url' => route('dept-tickets.index')],
        ];
    }

    public function agent(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'url' => route('agent.overview')],
            ['key' => 'fee', 'label' => 'Fee', 'url' => route('agent.fee')],
            ['key' => 'insentif', 'label' => 'Form Insentif', 'url' => route('agent.insentif')],
            ['key' => 'status-request', 'label' => 'Status Request', 'url' => route('agent.requests')],
            ['key' => 'monitor', 'label' => 'Monitor CS', 'url' => route('cs-scope.index')],
            ['key' => 'create-ticket', 'label' => 'Create Ticket', 'url' => route('ma.tickets.index')],
        ];
    }

    public function csScope(): array
    {
        return [
            ['key' => 'monitor', 'label' => 'Monitor', 'url' => route('cs-scope.index')],
        ];
    }

    public function csMonitor(): array
    {
        return [
            ['key' => 'cs-monitor', 'label' => 'Monitor CS Absen', 'url' => route('cs-monitor.index')],
            ['key' => 'cs-monitor-users', 'label' => 'List User', 'url' => route('cs-monitor.users')],
        ];
    }

    /**
     * Badge counts are per-user "unseen since last visit" (via
     * NotificationReadService), not raw totals - they drop to zero once the
     * user opens that menu's page (see markSeen() calls in
     * CenterSupportController::index()/DepartmentTicketController::index()).
     */
    public function centerSupport(User $user): array
    {
        $reads = app(NotificationReadService::class);

        // This menu is rebuilt on every CS Pusat page nav (it's the sidebar),
        // so these badge counts would otherwise re-run on every click -
        // cached briefly per user so a burst of navigation shares one count.
        [$pendingAuto, $openTickets] = Cache::remember('center-support:sidebar-badges:'.$user->id, 10, fn () => [
            $reads->unseenCount(
                $user,
                'center-support-tickets',
                SupportTicket::query()->whereNotNull('submitted_to_center_at')->whereNull('center_updated_at'),
                'submitted_to_center_at',
            ),
            $reads->unseenCount(
                $user,
                'dept-tickets',
                MerchantTicket::query()->whereIn('status', ['open', 'in_progress']),
                'last_message_at',
            ),
        ]);

        $menu = [
            ['key' => 'tickets', 'label' => 'Tickets', 'url' => route('center-support.tickets'), 'badge' => $pendingAuto ?: null],
            ['key' => 'merchant-support', 'label' => 'Tiket Toko', 'url' => route('dept-tickets.index'), 'badge' => $openTickets ?: null],
            ['key' => 'bot-monitoring', 'label' => 'Monitoring Bot Telegram', 'url' => route('center-support.bot-monitoring')],
        ];

        // MA/superadmin also open CS Pusat pages, but broadcast is CS Pusat only.
        if ($user->role === 'cs_pusat') {
            $menu[] = ['key' => 'broadcast-telegram', 'label' => 'Broadcast Telegram', 'url' => route('center-support.broadcast')];
        }

        return $menu;
    }

    public function merchantCs(Merchant $merchant): array
    {
        if ($merchant->isScript()) {
            $menu = [
                ['key' => 'tickets', 'label' => 'Tiket status', 'url' => route('merchant.cs.tickets', $merchant)],
                ['key' => 'history', 'label' => 'History TRX', 'url' => route('merchant.cs.history', $merchant)],
            ];
        } else {
            $menu = [
                ['key' => 'tickets', 'label' => 'Tickets', 'url' => route('merchant.cs.tickets', $merchant)],
                ['key' => 'topup', 'label' => 'Topup Request', 'url' => route('merchant.cs.topup', $merchant)],
                ['key' => 'checklist', 'label' => 'Sukses Checklist', 'url' => route('merchant.cs.checklist', $merchant)],
            ];
        }

        if ($merchant->general_ticket_enabled) {
            $menu[] = ['key' => 'support-ticket', 'label' => 'Create Ticket', 'url' => route('merchant.tickets.index', $merchant)];
        }

        return $menu;
    }

    public function merchantFinance(Merchant $merchant): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'url' => route('merchant.finance.overview', $merchant)],
            ['key' => 'settlement', 'label' => 'Settlement', 'url' => route('merchant.finance.settlement', $merchant)],
            ['key' => 'report', 'label' => 'Report', 'url' => route('merchant.finance.report', $merchant)],
        ];
    }

    public function merchantAdmin(Merchant $merchant): array
    {
        $menu = [
            ['key' => 'users', 'label' => 'Data User', 'url' => route('merchant.admin.users', $merchant)],
            ['key' => 'settings', 'label' => 'Atur Minimum Topup', 'url' => route('merchant.admin.settings', $merchant)],
            ['key' => 'logs', 'label' => 'Log Aktivitas', 'url' => route('merchant.admin.logs', $merchant)],
        ];

        if ($merchant->isCm()) {
            $menu[] = ['key' => 'qris', 'label' => 'Topup Request', 'url' => route('merchant.admin.qris', $merchant)];
            $menu[] = ['key' => 'checklist', 'label' => 'Sukses Checklist', 'url' => route('merchant.admin.checklist', $merchant)];
        } else {
            $menu[] = ['key' => 'history', 'label' => 'History TRX', 'url' => route('merchant.admin.history', $merchant)];
        }

        $menu[] = ['key' => 'finance', 'label' => 'Toko Finance', 'url' => route('merchant.finance.overview', $merchant)];
        $menu[] = ['key' => 'cs', 'label' => 'Toko CS', 'url' => route('merchant.cs.tickets', $merchant)];

        if ($merchant->general_ticket_enabled) {
            $menu[] = ['key' => 'support-ticket', 'label' => 'Create Ticket', 'url' => route('merchant.tickets.index', $merchant)];
        }

        return $menu;
    }
}
