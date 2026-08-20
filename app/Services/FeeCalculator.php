<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\User;

class FeeCalculator
{
    public function cascade(float $base, float $connection, float $settlement, float $maMargin, float $agentMargin, float $tokoSpread = 0): float
    {
        return $base + $connection + $settlement + $maMargin + $agentMargin + $tokoSpread;
    }

    public function maPrice(User $ma): float
    {
        return $this->cascade(
            (float) $ma->base_hg_percent,
            (float) $ma->connection_fee_percent,
            (float) $ma->settlement_fee_percent,
            (float) $ma->ma_fee_percent,
            0,
        );
    }

    public function agentPrice(Agent $agent): float
    {
        return $this->cascade(
            (float) $agent->base_hg_percent,
            (float) $agent->connection_fee_percent,
            (float) $agent->settlement_fee_percent,
            (float) $agent->ma_fee_percent,
            (float) $agent->default_agent_fee_percent,
        );
    }

    public function merchantPrice(array $components): float
    {
        return $this->cascade(
            (float) ($components['base_mdr_percent'] ?? 0),
            (float) ($components['connection_fee_percent'] ?? 0),
            (float) ($components['settlement_fee_percent'] ?? 0),
            (float) ($components['ma_fee_percent'] ?? 0),
            (float) ($components['agent_fee_percent'] ?? 0),
            (float) ($components['toko_fee_percent'] ?? 0),
        );
    }

    public function tokoResidual(float $merchantMdr, float $base, float $connection, float $settlement, float $maFee, float $agentFee): float
    {
        return max(0.0, $merchantMdr - $base - $connection - $settlement - $maFee - $agentFee);
    }

    public function residual(float $total, float $knownSum): float
    {
        return max(0.0, $total - $knownSum);
    }
}
