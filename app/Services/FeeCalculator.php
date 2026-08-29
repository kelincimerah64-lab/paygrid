<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\User;

class FeeCalculator
{
    public function maPrice(User $ma): float
    {
        return (float) $ma->ma_fee_percent;
    }

    public function agentPrice(Agent $agent): float
    {
        return (float) $agent->default_agent_fee_percent;
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
