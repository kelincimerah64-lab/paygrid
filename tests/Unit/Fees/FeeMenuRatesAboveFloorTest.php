<?php

namespace Tests\Unit\Fees;

use App\Rules\FeeMenuRatesAboveFloor;
use Tests\TestCase;

class FeeMenuRatesAboveFloorTest extends TestCase
{
    public function test_zero_or_blank_rates_always_pass(): void
    {
        $rule = new FeeMenuRatesAboveFloor('agent', 'cm');
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 0, 'everyday' => null, 'same_day' => ''], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertSame([], $failures);
    }

    public function test_rate_below_floor_fails_with_label_and_floor(): void
    {
        $rule = new FeeMenuRatesAboveFloor('agent', 'cm');
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 0.50], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('H+1', $failures[0]);
        $this->assertStringContainsString('1%', $failures[0]);
    }

    public function test_rate_at_or_above_floor_passes(): void
    {
        $rule = new FeeMenuRatesAboveFloor('agent', 'cm');
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 1.00, 'everyday' => 1.10], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertSame([], $failures);
    }

    public function test_multiple_offending_rows_each_report_a_failure(): void
    {
        $rule = new FeeMenuRatesAboveFloor('agent', 'cm');
        $failures = [];

        $rule->validate('fee_menu_rates', ['h_plus_1' => 0.10, 'everyday' => 0.20], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertCount(2, $failures);
    }
}
