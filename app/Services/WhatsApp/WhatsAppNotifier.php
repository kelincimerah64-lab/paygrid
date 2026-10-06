<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-way sender only - this app never reads replies from WhatsApp. Targets
 * WAHA's REST API (POST {base}/api/sendText, PUT/DELETE
 * {base}/api/{session}/chats/{chatId}/messages/{messageId}). Swap these
 * methods if the provider ever changes; until api_url/api_key are configured
 * they just log, so the rest of the ticket flow (claim, reminder, transfer,
 * approve) can be built and tested end-to-end before any WA credentials exist.
 */
class WhatsAppNotifier
{
    /**
     * @return string|null the WAHA message id (for later edit/delete), or null if not sent
     */
    public function send(string $groupId, string $message): ?string
    {
        [$apiUrl, $apiKey, $session] = $this->credentials();

        if (! $apiUrl || ! $apiKey) {
            Log::info('paygrid.whatsapp.send_skipped_not_configured', ['group_id' => $groupId, 'message' => $message]);

            return null;
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->post($apiUrl.'/api/sendText', [
                    'session' => $session,
                    'chatId' => $groupId,
                    'text' => $message,
                ])->throw();

            // WAHA's sendText response nests the id ({"id":{"id":"...", "_serialized":"true_123@g.us_ABC"}});
            // the edit/delete endpoints need the full "_serialized" form, not the bare inner id.
            return $response->json('id._serialized');
        } catch (\Throwable $exception) {
            Log::warning('paygrid.whatsapp.send_failed', ['group_id' => $groupId, 'message' => $exception->getMessage()]);

            return null;
        }
    }

    public function edit(string $chatId, string $messageId, string $text): bool
    {
        [$apiUrl, $apiKey, $session] = $this->credentials();

        if (! $apiUrl || ! $apiKey) {
            Log::info('paygrid.whatsapp.edit_skipped_not_configured', ['chat_id' => $chatId, 'message_id' => $messageId]);

            return false;
        }

        try {
            // Edit round-trips through WhatsApp's own network (not just WAHA's local
            // state) and measured ~5s in production - an 8s timeout here intermittently
            // failed real edits.
            Http::timeout(20)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->put($apiUrl.'/api/'.$session.'/chats/'.rawurlencode($chatId).'/messages/'.rawurlencode($messageId), [
                    'text' => $text,
                ])->throw();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('paygrid.whatsapp.edit_failed', ['chat_id' => $chatId, 'message_id' => $messageId, 'message' => $exception->getMessage()]);

            return false;
        }
    }

    public function delete(string $chatId, string $messageId): bool
    {
        [$apiUrl, $apiKey, $session] = $this->credentials();

        if (! $apiUrl || ! $apiKey) {
            Log::info('paygrid.whatsapp.delete_skipped_not_configured', ['chat_id' => $chatId, 'message_id' => $messageId]);

            return false;
        }

        try {
            Http::timeout(20)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->delete($apiUrl.'/api/'.$session.'/chats/'.rawurlencode($chatId).'/messages/'.rawurlencode($messageId))
                ->throw();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('paygrid.whatsapp.delete_failed', ['chat_id' => $chatId, 'message_id' => $messageId, 'message' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function credentials(): array
    {
        return [
            rtrim((string) config('paygrid.whatsapp.api_url'), '/'),
            config('paygrid.whatsapp.api_key'),
            config('paygrid.whatsapp.session', 'default'),
        ];
    }
}
