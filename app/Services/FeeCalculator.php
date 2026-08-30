<?php

namespace App\Services;

class FeeCalculator
{
    public function tokoResidual(float $merchantMdr, float $base, float $connection, float $settlement, float $maFee, float $agentFee): float
    {
        return max(0.0, $merchantMdr - $base - $connection - $settlement - $maFee - $agentFee);
    }

    public function residual(float $total, float $knownSum): float
    {
        return max(0.0, $total - $knownSum);
    }
}
