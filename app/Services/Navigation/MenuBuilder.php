<?php

namespace App\Services\Navigation;

use App\Models\Merchant;

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

    public function ma(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'url' => route('ma.overview')],
            ['key' => 'report', 'label' => 'Report', 'url' => route('ma.report')],
            ['key' => 'fee', 'label' => 'Fee', 'url' => route('ma.fee')],
            ['key' => 'approval', 'label' => 'Request Approval', 'url' => route('ma.approvals')],
            ['key' => 'mapping', 'label' => 'Mapping Agen', 'url' => route('ma.mapping')],
            ['key' => 'stores', 'label' => 'List Toko', 'url' => route('ma.stores')],
            ['key' => 'agents', 'label' => 'Agen', 'url' => route('ma.agents')],
            ['key' => 'create-store', 'label' => 'Create Toko', 'url' => route('ma.create-store')],
            ['key' => 'bot-monitoring', 'label' => 'Monitoring Bot Telegram', 'url' => route('ma.bot-monitoring')],
        ];
    }

    public function agent(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'url' => route('agent.overview')],
            ['key' => 'fee', 'label' => 'Fee', 'url' => route('agent.fee')],
            ['key' => 'create-store', 'label' => 'Create Toko', 'url' => route('agent.create-store')],
            ['key' => 'status-request', 'label' => 'Status Request', 'url' => route('agent.requests')],
        ];
    }

    public function merchantCs(Merchant $merchant): array
    {
        if ($merchant->isScript()) {
            return [
                ['key' => 'tickets', 'label' => 'Tiket status', 'url' => route('merchant.cs.tickets', $merchant)],
                ['key' => 'history', 'label' => 'History TRX', 'url' => route('merchant.cs.history', $merchant)],
            ];
        }

        return [
            ['key' => 'tickets', 'label' => 'Tickets', 'url' => route('merchant.cs.tickets', $merchant)],
            ['key' => 'topup', 'label' => 'Topup Request', 'url' => route('merchant.cs.topup', $merchant)],
            ['key' => 'checklist', 'label' => 'Sukses Checklist', 'url' => route('merchant.cs.checklist', $merchant)],
        ];
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

        return $menu;
    }
}
