<?php

namespace App\Console\Commands;

use App\Services\BotTele\BotTeleDispatcher;
use App\Services\BotTele\TelegramClient;
use Illuminate\Console\Command;

/**
 * Local-testing stand-in for the webhook: long-polls getUpdates and feeds each
 * update to the same dispatcher. Use a separate test bot — Telegram refuses
 * getUpdates while a webhook is set, and the production bot belongs to n8n
 * until cutover.
 */
class BotTelePollCommand extends Command
{
    protected $signature = 'bottele:poll
        {--once : Handle one batch of updates and exit}
        {--wait=25 : Long-poll seconds per request}';

    protected $description = 'Receive Bot_Tele updates by polling (local testing without a public webhook URL).';

    public function handle(TelegramClient $telegram, BotTeleDispatcher $bot): int
    {
        if ((string) config('bottele.bot_token') === '') {
            $this->error('Set BOTTELE_TELEGRAM_BOT_TOKEN (a test bot) first.');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->confirm('This is production. Polling makes this machine answer the bot. Continue?', false)) {
            return self::SUCCESS;
        }

        $this->info('Polling Telegram... (Ctrl+C to stop)');
        $offset = 0;

        do {
            $updates = $telegram->getUpdates($offset, $this->option('once') ? 0 : (int) $this->option('wait'));

            if ($updates === null) {
                $this->warn('getUpdates failed (webhook still set? check storage/logs). Retrying in 5s.');
                sleep(5);

                continue;
            }

            foreach ($updates as $update) {
                $offset = (int) $update['update_id'] + 1;
                $this->line('update '.$update['update_id'].': '.implode(',', array_diff(array_keys($update), ['update_id'])));

                try {
                    $bot->handle($update);
                } catch (\Throwable $exception) {
                    report($exception);
                    $this->error($exception->getMessage());
                }
            }
        } while (! $this->option('once'));

        // Acknowledge the last batch so the next run does not replay it.
        if ($offset > 0) {
            $telegram->getUpdates($offset, 0);
        }

        return self::SUCCESS;
    }
}
