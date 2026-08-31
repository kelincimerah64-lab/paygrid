<?php

namespace Tests\Unit\Fees;

use App\Rules\FeeMenuRatesAboveFloor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeMenuRatesAboveFloorTest extends TestCase
{
    use RefreshDatabase;


    public function test_zero_or_blank_rates_always_pass(): void
    {
        $rule = new FeeMenuRatesAboveFloor('ma', null);
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 0, 'everyday' => null, 'same_day' => ''], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertSame([], $failures);
    }

    public function test_rate_below_ma_floor_fails_with_label_and_floor(): void
    {
        $rule = new FeeMenuRatesAboveFloor('ma', null);
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 0.50], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('Based + H+1', $failures[0]);
        $this->assertStringContainsString('0.8%', $failures[0]);
    }

    public function test_rate_at_or_above_ma_floor_passes(): void
    {
        $rule = new FeeMenuRatesAboveFloor('ma', null);
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 0.80, 'everyday' => 0.85], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertSame([], $failures);
    }

    public function test_multiple_offending_ma_rows_each_report_a_failure(): void
    {
        $rule = new FeeMenuRatesAboveFloor('ma', null);
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 0.10, 'everyday' => 0.20], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertCount(2, $failures);
    }

    public function test_agent_and_merchant_rates_are_free_form_with_no_floor(): void
    {
        foreach (['agent', 'merchant'] as $role) {
            $rule = new FeeMenuRatesAboveFloor($role, null);
            $failures = [];

            $rule->validate('fee_menu_rates', ['h_plus_1' => 0.01, 'same_day' => 999], function ($message) use (&$failures) {
                $failures[] = $message;
            });

            $this->assertSame([], $failures, "{$role} rates should never be rejected for being too low");
        }
    }
}
