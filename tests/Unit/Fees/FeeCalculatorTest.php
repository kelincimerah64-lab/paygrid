<?php

namespace Tests\Unit\Fees;

use App\Services\FeeCalculator;
use PHPUnit\Framework\TestCase;

class FeeCalculatorTest extends TestCase
{
    public function test_cascade_matches_prd_worked_example(): void
    {
        $calculator = new FeeCalculator();

        $maPrice = $calculator->cascade(base: 0.80, connection: 0.05, settlement: 0.05, maMargin: 0.05, agentMargin: 0);
        $this->assertEqualsWithDelta(0.95, $maPrice, 0.0001);

        $agentPrice = $calculator->cascade(base: 0.80, connection: 0.05, settlement: 0.05, maMargin: 0.05, agentMargin: 0.10);
        $this->assertEqualsWithDelta(1.05, $agentPrice, 0.0001);

        $tokoPrice = $calculator->cascade(base: 0.80, connection: 0.05, settlement: 0.05, maMargin: 0.05, agentMargin: 0.10, tokoSpread: 0.15);
        $this->assertEqualsWithDelta(1.20, $tokoPrice, 0.0001);
    }

    public function test_cascade_matches_pak_fernando_wa_example(): void
    {
        $calculator = new FeeCalculator();

        $agentPrice = $calculator->cascade(base: 0.85, connection: 0, settlement: 0, maMargin: 0.25, agentMargin: 0);
        $this->assertEqualsWithDelta(1.10, $agentPrice, 0.0001);

        $tokoPrice = $calculator->cascade(base: 0.85, connection: 0, settlement: 0, maMargin: 0.25, agentMargin: 0.05);
        $this->assertEqualsWithDelta(1.15, $tokoPrice, 0.0001);
    }

    public function test_merchant_price_sums_all_six_components(): void
    {
        $calculator = new FeeCalculator();

        $result = $calculator->merchantPrice([
            'base_mdr_percent' => 0.80,
            'connection_fee_percent' => 0.05,
            'settlement_fee_percent' => 0.05,
            'ma_fee_percent' => 0.05,
            'agent_fee_percent' => 0.10,
            'toko_fee_percent' => 0.15,
        ]);

        $this->assertEqualsWithDelta(1.20, $result, 0.0001);
    }

    public function test_merchant_price_defaults_missing_components_to_zero(): void
    {
        $calculator = new FeeCalculator();

        $result = $calculator->merchantPrice([
            'base_mdr_percent' => 0.85,
            'ma_fee_percent' => 0.25,
            'agent_fee_percent' => 0.05,
        ]);

        $this->assertEqualsWithDelta(1.15, $result, 0.0001);
    }

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
