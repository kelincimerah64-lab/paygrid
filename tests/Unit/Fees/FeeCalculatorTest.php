<?php

namespace Tests\Unit\Fees;

use App\Services\FeeCalculator;
use PHPUnit\Framework\TestCase;

class FeeCalculatorTest extends TestCase
{
    public function test_toko_residual_is_never_negative(): void
    {
        $calculator = new FeeCalculator();

        $residual = $calculator->tokoResidual(merchantMdr: 1.00, base: 0.80, connection: 0.05, settlement: 0.05, maFee: 0.05, agentFee: 0.10);
        $this->assertSame(0.0, $residual);

        $positiveResidual = $calculator->tokoResidual(merchantMdr: 1.20, base: 0.80, connection: 0.05, settlement: 0.05, maFee: 0.05, agentFee: 0.10);
        $this->assertEqualsWithDelta(0.15, $positiveResidual, 0.0001);
    }

    public function test_residual_matches_ma_back_solve_used_by_ma_controller(): void
    {
        $calculator = new FeeCalculator();

        // MaController::mapAgent's original formula: max(0, merchantMdr - baseCost - agentFee).
        $this->assertEqualsWithDelta(0.40, $calculator->residual(1.20, 0.80), 0.0001);
        $this->assertSame(0.0, $calculator->residual(0.90, 1.20));
    }
}
