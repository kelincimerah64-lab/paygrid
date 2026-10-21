<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-way sender for superadmin broadcasts. Groups come from
 * TELEGRAM_BROADCAST_GROUPS as "Name:chat_id,Name:chat_id"; the bot must
 * already be a member of each group.
 */
class TelegramBroadcaster
{
    public function groups(): array
    {
        $groups = [];

        foreach (explode(',', (string) config('paygrid.telegram_broadcast.groups')) as $entry) {
            $separator = strrpos($entry, ':');
            if ($separator === false) {
                continue;
            }

            $name = trim(substr($entry, 0, $separator));
            $chatId = trim(substr($entry, $separator + 1));
            if ($name !== '' && $chatId !== '') {
                $groups[] = ['name' => $name, 'chat_id' => $chatId];
            }
        }

        return $groups;
    }

    public function isConfigured(): bool
    {
        return (string) config('paygrid.telegram_broadcast.bot_token') !== '' && $this->groups() !== [];
    }

    public function send(string $chatId, string $text): bool
    {
        $token = (string) config('paygrid.telegram_broadcast.bot_token');

        if ($token === '') {
            Log::info('paygrid.telegram.send_skipped_not_configured', ['chat_id' => $chatId]);

            return false;
        }

        try {
            Http::timeout(8)
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                ])->throw();

            return true;
        } catch (\Throwable $exception) {
            // Never log the exception message as-is: Telegram request URLs contain the bot token.
            Log::warning('paygrid.telegram.send_failed', [
                'chat_id' => $chatId,
                'message' => str_replace($token, '***', $exception->getMessage()),
            ]);

            return false;
        }
    }
}
