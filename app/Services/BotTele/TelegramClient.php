<?php

namespace App\Services\BotTele;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Bot API client for the support bot. Every call is best-effort: a failure
 * is logged (token masked) and returns null so the rest of the flow continues.
 * n8n stopped the whole chain on the first failed Telegram node instead.
 */
class TelegramClient
{
    /** @return array|null The decoded `result`, or null on failure. */
    public function call(string $method, array $payload, int $timeout = 10): mixed
    {
        $token = (string) config('bottele.bot_token');

        if ($token === '') {
            Log::warning('bottele.telegram_not_configured', ['method' => $method]);

            return null;
        }

        try {
            $response = Http::timeout($timeout)
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload)
                ->throw();

            return $response->json('result');
        } catch (\Throwable $exception) {
            // Telegram request URLs contain the bot token, so never log the message as-is.
            Log::warning('bottele.telegram_call_failed', [
                'method' => $method,
                'chat_id' => $payload['chat_id'] ?? null,
                'message' => str_replace($token, '***', $exception->getMessage()),
            ]);

            return null;
        }
    }

    /** Returns the new message_id, or null when sending failed. */
    public function sendMessage(mixed $chatId, string $text, ?array $keyboard = null, bool $html = true): ?int
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];
        if ($html) {
            $payload['parse_mode'] = 'HTML';
        }
        if ($keyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $result = $this->call('sendMessage', $payload);

        return isset($result['message_id']) ? (int) $result['message_id'] : null;
    }

    /** $keyboard null leaves reply_markup out, which makes Telegram drop any existing buttons. */
    public function editMessageText(mixed $chatId, mixed $messageId, string $text, ?array $keyboard = null): void
    {
        if (! $this->filled($chatId) || ! $this->filled($messageId)) {
            return;
        }

        $payload = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $this->call('editMessageText', $payload);
    }

    public function removeKeyboard(mixed $chatId, mixed $messageId): void
    {
        if ($this->filled($chatId) && $this->filled($messageId)) {
            $this->call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
        }
    }

    public function deleteMessage(mixed $chatId, mixed $messageId): void
    {
        if ($this->filled($chatId) && $this->filled($messageId)) {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        }
    }

    /** Long-poll for local testing (bottele:poll). Only works while the bot has no webhook set. */
    public function getUpdates(int $offset, int $waitSeconds): ?array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $waitSeconds,
            'allowed_updates' => ['message', 'callback_query'],
        ], $waitSeconds + 10);

        return is_array($result) ? $result : null;
    }

    public function sendPhoto(mixed $chatId, string $fileId, string $caption): void
    {
        $this->call('sendPhoto', ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => $caption, 'parse_mode' => 'HTML']);
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 0 && $value !== '0';
    }
}
