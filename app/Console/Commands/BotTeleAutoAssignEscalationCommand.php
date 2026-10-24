<?php

namespace App\Console\Commands;

use App\Services\BotTele\AutomationFlow;
use Illuminate\Console\Command;

/** Port of the n8n "Auto_Assign_Escalation" Schedule Trigger workflow; scheduled every minute. */
class BotTeleAutoAssignEscalationCommand extends Command
{
    protected $signature = 'bottele:check-auto-assign-escalation';

    protected $description = 'Escalate auto-assigned tickets not started within 10 minutes to the PIC.';

    public function handle(AutomationFlow $automation): int
    {
        // Scheduled everywhere, but quiet until the bot is configured.
        if ((string) config('bottele.bot_token') === '' || (string) config('bottele.pic_escalation_chat_id') === '') {
            return self::SUCCESS;
        }

        $result = $automation->autoAssignEscalation();
        if (array_sum((array) $result) > 0) {
            $this->info(json_encode($result));
        }

        return self::SUCCESS;
    }
}
