<?php

namespace Tests\Unit\BotTele;

use App\Services\BotTele\SlaCalculator;
use Tests\TestCase;

class SlaCalculatorTest extends TestCase
{
    public function test_durations_are_in_seconds(): void
    {
        $sla = (new SlaCalculator)->calculate('2026-10-23 09:00:00', '2026-10-23 09:05:00', '2026-10-23 09:06:30', '2026-10-23 10:00:00');

        $this->assertSame([
            'pickup_minutes' => 300,
            'investigation_lag_minutes' => 90,
            'handling_minutes' => 3210,
            'total_resolution_minutes' => 3600,
        ], $sla);
    }

    public function test_out_of_order_timestamps_give_negative_values(): void
    {
        $sla = (new SlaCalculator)->calculate('2026-10-23 10:00:00', '2026-10-23 09:00:00', '2026-10-23 09:00:00', '2026-10-23 08:00:00');

        $this->assertSame(-3600, $sla['pickup_minutes']);
        $this->assertSame(-3600, $sla['handling_minutes']);
        $this->assertSame(-7200, $sla['total_resolution_minutes']);
    }

    public function test_missing_or_unparseable_timestamps_give_null(): void
    {
        $sla = (new SlaCalculator)->calculate('2026-10-23 09:00:00', null, '', 'not a date');

        $this->assertNull($sla['pickup_minutes']);
        $this->assertNull($sla['investigation_lag_minutes']);
        $this->assertNull($sla['handling_minutes']);
        $this->assertNull($sla['total_resolution_minutes']);
    }
}
