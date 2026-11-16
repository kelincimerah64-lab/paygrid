<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-way sender for CS Pusat broadcasts. Groups come from
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

    /** Returns Telegram's message_id, or null when sending failed. */
    public function send(string $chatId, string $text): ?int
    {
        $response = $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);

        return $response === null ? null : (int) $response['result']['message_id'];
    }

    /** Telegram only lets a bot delete its own group messages for 48 hours after sending. */
    public function delete(string $chatId, int $messageId): bool
    {
        return $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]) !== null;
    }

    private function call(string $method, array $payload): ?array
    {
        $token = (string) config('paygrid.telegram_broadcast.bot_token');

        if ($token === '') {
            Log::info('paygrid.telegram.'.$method.'_skipped_not_configured', ['chat_id' => $payload['chat_id']]);

            return null;
        }

        try {
            return Http::timeout(8)
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload)
                ->throw()
                ->json();
        } catch (\Throwable $exception) {
            // Never log the exception message as-is: Telegram request URLs contain the bot token.
            Log::warning('paygrid.telegram.'.$method.'_failed', [
                'chat_id' => $payload['chat_id'],
                'message' => str_replace($token, '***', $exception->getMessage()),
            ]);

            return null;
        }
    }
}
