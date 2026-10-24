<?php

namespace App\Console\Commands;

use App\Services\BotTele\AutomationFlow;
use Illuminate\Console\Command;

/** Port of the n8n "PIC_Ticket_Expiry" Schedule Trigger workflow; scheduled every minute. */
class BotTelePicExpiryCommand extends Command
{
    protected $signature = 'bottele:check-pic-expiry';

    protected $description = 'Expire escalated tickets the PIC did not start within 10 minutes.';

    public function handle(AutomationFlow $automation): int
    {
        // Scheduled everywhere, but quiet until the bot is configured.
        if ((string) config('bottele.bot_token') === '') {
            return self::SUCCESS;
        }

        $result = $automation->picExpiry();
        if (array_sum((array) $result) > 0) {
            $this->info(json_encode($result));
        }

        return self::SUCCESS;
    }
}
