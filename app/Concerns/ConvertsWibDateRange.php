<?php

namespace App\Concerns;

use Carbon\CarbonImmutable;

/**
 * Converts a WIB (Asia/Jakarta) calendar-date range - as typically supplied
 * by `from`/`to` request params throughout this app - into a UTC datetime
 * range usable with whereBetween() against UTC-stored timestamp columns
 * (submitted_at, created_at, gateway_created_at, etc).
 *
 * whereDate($col, '>=', $from)->whereDate($col, '<=', $to) against a raw WIB
 * date string is wrong for those columns: it compares the column's stored
 * UTC calendar date against a WIB calendar date, silently excluding rows
 * timestamped 00:00-06:59 WIB (their UTC date is still the previous day).
 * This bug has recurred across multiple controllers/services - use this
 * helper instead of whereDate() whenever filtering a timestamp column by a
 * WIB "from"/"to" date range.
 */
trait ConvertsWibDateRange
{
    protected function wibRangeToUtc(?string $from, ?string $to): array
    {
        $start = $from ? CarbonImmutable::parse($from, 'Asia/Jakarta')->startOfDay()->utc() : null;
        $end = $to ? CarbonImmutable::parse($to, 'Asia/Jakarta')->endOfDay()->utc() : null;

        return [$start, $end];
    }

    protected function wibDayToUtcRange(CarbonImmutable|string $date): array
    {
        $day = $date instanceof CarbonImmutable
            ? $date->setTimezone('Asia/Jakarta')->toDateString()
            : $date;

        return $this->wibRangeToUtc($day, $day);
    }
}
