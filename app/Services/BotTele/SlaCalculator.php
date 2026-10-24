<?php

namespace App\Services\BotTele;

/**
 * Port of n8n "Calculate SLA - Resolved". The result keys keep the historical
 * *_minutes names but the values are SECONDS. Unlike n8n, timestamps are parsed
 * as Asia/Jakarta (n8n parsed them in the server's zone, which is where the
 * historical negative values came from); a missing timestamp yields null.
 */
class SlaCalculator
{
    /**
     * @return array{pickup_minutes: ?int, investigation_lag_minutes: ?int, handling_minutes: ?int, total_resolution_minutes: ?int}
     */
    public function calculate(mixed $createdAt, mixed $assignedAt, mixed $startedAt, mixed $completedAt): array
    {
        $created = BotTime::parse($createdAt);
        $assigned = BotTime::parse($assignedAt);
        $started = BotTime::parse($startedAt);
        $completed = BotTime::parse($completedAt);

        return [
            'pickup_minutes' => $this->diffSeconds($created, $assigned),
            'investigation_lag_minutes' => $this->diffSeconds($assigned, $started),
            'handling_minutes' => $this->diffSeconds($started, $completed),
            'total_resolution_minutes' => $this->diffSeconds($created, $completed),
        ];
    }

    private function diffSeconds(?\DateTimeInterface $from, ?\DateTimeInterface $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        return $to->getTimestamp() - $from->getTimestamp();
    }
}
