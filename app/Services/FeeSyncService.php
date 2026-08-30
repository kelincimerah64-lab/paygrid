<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;

/**
 * Keeps the merchants.{merchant_mdr_percent,agent_fee_percent,ma_fee_percent}
 * snapshot columns (read by the SQL-based fee reports) in sync with the
 * per-menu fee_menu_rates tables on Merchant/Agent/User, since a merchant's
 * fee now depends on which menu it runs plus its own agent/MA's rate for
 * that same menu, not a single flat number per tier.
 */
class FeeSyncService
{
    public function agentRateFor(Agent $agent, string $menuKey): float
    {
        return (float) (((array) ($agent->fee_menu_rates ?? []))[$menuKey] ?? 0);
    }

    public function maRateFor(?User $ma, string $menuKey): float
    {
        return $ma === null ? 0.0 : (float) (((array) ($ma->fee_menu_rates ?? []))[$menuKey] ?? 0);
    }

    public function snapshotFor(Agent $agent, string $menuKey, float $merchantRate): array
    {
        return [
            'merchant_mdr_percent' => $merchantRate,
            'agent_fee_percent' => $this->agentRateFor($agent, $menuKey),
            'ma_fee_percent' => $this->maRateFor($agent->ma, $menuKey),
        ];
    }

    public function resyncAgent(Agent $agent): void
    {
        $rates = (array) ($agent->fee_menu_rates ?? []);
        Merchant::query()->where('agent_id', $agent->id)->whereNotNull('fee_menu')
            ->get(['id', 'fee_menu'])
            ->groupBy('fee_menu')
            ->each(function ($group, $menuKey) use ($rates) {
                Merchant::query()->whereIn('id', $group->pluck('id'))
                    ->update(['agent_fee_percent' => (float) ($rates[$menuKey] ?? 0)]);
            });
    }

    public function resyncMa(User $ma): void
    {
        $rates = (array) ($ma->fee_menu_rates ?? []);
        $agentIds = Agent::query()->where('ma_user_id', $ma->id)->pluck('id');
        Merchant::query()->whereIn('agent_id', $agentIds)->whereNotNull('fee_menu')
            ->get(['id', 'fee_menu'])
            ->groupBy('fee_menu')
            ->each(function ($group, $menuKey) use ($rates) {
                Merchant::query()->whereIn('id', $group->pluck('id'))
                    ->update(['ma_fee_percent' => (float) ($rates[$menuKey] ?? 0)]);
            });
    }
}
