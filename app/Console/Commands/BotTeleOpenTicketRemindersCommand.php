<?php

namespace App\Console\Commands;

use App\Services\BotTele\AutomationFlow;
use Illuminate\Console\Command;

/** Port of the n8n "remind_tiket_mnt_v2" Schedule Trigger workflow; scheduled every minute. */
class BotTeleOpenTicketRemindersCommand extends Command
{
    protected $signature = 'bottele:check-open-ticket-reminders';

    protected $description = 'Send the 10/20/30-minute OPEN ticket reminders, then auto-assign a random available tech.';

    public function handle(AutomationFlow $automation): int
    {
        // Scheduled everywhere, but quiet until the bot is configured.
        if ((string) config('bottele.bot_token') === '' || (string) config('bottele.tech_chat_id') === '') {
            return self::SUCCESS;
        }

        $result = $automation->openTicketReminders();
        if (array_sum((array) $result) > 0) {
            $this->info(json_encode($result));
        }

        return self::SUCCESS;
    }
}
