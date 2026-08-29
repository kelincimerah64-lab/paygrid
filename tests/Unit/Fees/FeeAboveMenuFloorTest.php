<?php

namespace Tests\Unit\Fees;

use App\Rules\FeeAboveMenuFloor;
use Tests\TestCase;

class FeeAboveMenuFloorTest extends TestCase
{
    public function test_it_fails_when_value_is_below_the_menu_floor(): void
    {
        $rule = new FeeAboveMenuFloor('merchant', 'cm', 'h_plus_1');
        $failed = false;

        $rule->validate('merchant_mdr_percent', 0.50, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    public function test_it_passes_when_value_is_at_or_above_the_menu_floor(): void
    {
        $rule = new FeeAboveMenuFloor('merchant', 'cm', 'h_plus_1');
        $failed = false;
        $fail = function () use (&$failed) { $failed = true; };

        $rule->validate('merchant_mdr_percent', 0.85, $fail);
        $rule->validate('merchant_mdr_percent', 1.20, $fail);

        $this->assertFalse($failed);
    }

    public function test_it_passes_for_engine_category_at_its_own_floor(): void
    {
        $rule = new FeeAboveMenuFloor('agent', 'engine', 'h_plus_1_api');
        $failed = false;

        $rule->validate('default_agent_fee_percent', 0.85, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_it_does_not_fail_when_menu_key_is_missing(): void
    {
        $rule = new FeeAboveMenuFloor('merchant', 'cm', null);
        $failed = false;

        $rule->validate('merchant_mdr_percent', 0, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }
}
