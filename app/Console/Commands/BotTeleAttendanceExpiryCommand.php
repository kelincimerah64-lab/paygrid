<?php

namespace App\Console\Commands;

use App\Services\BotTele\AutomationFlow;
use Illuminate\Console\Command;

/** Port of the n8n "absen_expired" Schedule Trigger workflow; scheduled every minute. */
class BotTeleAttendanceExpiryCommand extends Command
{
    protected $signature = 'bottele:check-attendance-expiry';

    protected $description = 'Mark technicians TIDAK_HADIR when their 8-hour check-in runs out.';

    public function handle(AutomationFlow $automation): int
    {
        // Scheduled everywhere, but quiet until the bot is configured.
        if ((string) config('bottele.bot_token') === '') {
            return self::SUCCESS;
        }

        $result = $automation->attendanceExpiry();
        if (array_sum((array) $result) > 0) {
            $this->info(json_encode($result));
        }

        return self::SUCCESS;
    }
}
