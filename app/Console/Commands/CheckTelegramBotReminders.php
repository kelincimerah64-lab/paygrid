<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TelegramTicketReminder;
use App\Services\TelegramBotMonitoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckTelegramBotReminders extends Command
{
    protected $signature = 'telegram:check-reminders';

    protected $description = 'Notify CS Pusat about Telegram bot tickets that have been open too long.';

    public function handle(TelegramBotMonitoringService $service): int
    {
        $overdue = $service->overdueTickets();

        if ($overdue->isEmpty()) {
            $this->info('No overdue Telegram bot tickets.');

            return self::SUCCESS;
        }

        $repeatMinutes = (int) config('paygrid.telegram_bot_monitoring.reminder_repeat_minutes', 30);
        $recipients = User::query()->where('role', 'cs_pusat')->get();
        $notified = 0;

        foreach ($overdue as $ticket) {
            $cacheKey = "telegram-reminder-sent:{$ticket['ticket_id']}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            foreach ($recipients as $recipient) {
                $recipient->notify(new TelegramTicketReminder($ticket));
            }

            Cache::put($cacheKey, true, now()->addMinutes($repeatMinutes));
            $notified++;
        }

        $this->info("Sent reminder(s) for {$notified} overdue ticket(s) to {$recipients->count()} CS Pusat user(s).");

        return self::SUCCESS;
    }
}
