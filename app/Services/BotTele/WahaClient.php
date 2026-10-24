<?php

namespace App\Services\BotTele;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Minimal WAHA (WhatsApp HTTP API) client for the IP Whitelist approval poll. Best-effort like TelegramClient. */
class WahaClient
{
    public function configured(): bool
    {
        return (string) config('bottele.waha.base_url') !== ''
            && (string) config('bottele.waha.api_key') !== ''
            && (string) config('bottele.waha.ip_approval_group_id') !== '';
    }

    public function sendText(string $chatId, string $text): void
    {
        $this->request('post', '/api/sendText', ['chatId' => $chatId, 'text' => $text]);
    }

    /** @return array|null The raw WAHA response (its shape is not verified yet, see pollId()). */
    public function sendPoll(string $chatId, string $name, array $options): ?array
    {
        return $this->request('post', '/api/sendPoll', [
            'chatId' => $chatId,
            'poll' => ['name' => $name, 'options' => $options, 'multipleAnswers' => false],
        ]);
    }

    /** "WAHA - Get Voter Contact": the voter's WhatsApp display name, if WAHA knows it. */
    public function contactName(string $contactId): ?string
    {
        if ($contactId === '') {
            return null;
        }

        $contact = $this->request('get', '/api/contacts', ['contactId' => $contactId]);

        foreach (['pushname', 'name', 'shortName'] as $field) {
            if (is_array($contact) && ! empty($contact[$field]) && is_string($contact[$field])) {
                return $contact[$field];
            }
        }

        return null;
    }

    /**
     * "Extract Poll ID". TODO(verify against real WAHA): n8n itself guessed these paths; the
     * historical sheet holds ids like "true_1203…@g.us_3EB0…_2780…@lid", i.e. the serialized form.
     */
    public static function pollId(?array $response): string
    {
        $candidates = [
            data_get($response, 'id._serialized'),
            data_get($response, 'id.id'),
            data_get($response, 'id'),
            data_get($response, '_data.id._serialized'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function request(string $method, string $path, array $payload): ?array
    {
        $payload['session'] = (string) config('bottele.waha.session', 'default');

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Api-Key' => (string) config('bottele.waha.api_key')])
                ->{$method}(rtrim((string) config('bottele.waha.base_url'), '/').$path, $payload)
                ->throw();

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $exception) {
            Log::warning('bottele.waha_call_failed', ['path' => $path, 'message' => $exception->getMessage()]);

            return null;
        }
    }
}
