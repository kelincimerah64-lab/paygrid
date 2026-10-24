<?php

namespace App\Services\BotTele;

use Carbon\CarbonImmutable;

/**
 * The bot stores every timestamp as an Asia/Jakarta wall-clock string
 * ("Y-m-d H:i:s"), exactly like the Google Sheet it replaces, while the app
 * itself runs in UTC. All reads and writes go through here.
 */
class BotTime
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function timezone(): string
    {
        return (string) config('bottele.timezone', 'Asia/Jakarta');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function nowString(): string
    {
        return self::now()->format(self::FORMAT);
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::timezone());
        } catch (\Throwable) {
            return null;
        }
    }
}
