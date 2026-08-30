<?php

namespace Tests\Unit\Fees;

use App\Rules\ExactlyOneFeeMenuFilled;
use PHPUnit\Framework\TestCase;

class ExactlyOneFeeMenuFilledTest extends TestCase
{
    public function test_passes_when_exactly_one_rate_is_positive(): void
    {
        $rule = new ExactlyOneFeeMenuFilled();
        $failures = [];

        $rule->validate('fee_menu_rates', ['based' => 0, 'h_plus_1' => 1.2, 'everyday' => 0], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertSame([], $failures);
    }

    public function test_fails_when_nothing_is_filled(): void
    {
        $rule = new ExactlyOneFeeMenuFilled();
        $failures = [];

        $rule->validate('fee_menu_rates', ['based' => 0, 'h_plus_1' => 0], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertCount(1, $failures);
    }

    public function test_fails_when_more_than_one_is_filled(): void
    {
        $rule = new ExactlyOneFeeMenuFilled();
        $failures = [];

        $rule->validate('fee_menu_rates', ['based' => 1.0, 'h_plus_1' => 1.2], function ($message) use (&$failures) {
            $failures[] = $message;
        });

        $this->assertCount(1, $failures);
    }
}
