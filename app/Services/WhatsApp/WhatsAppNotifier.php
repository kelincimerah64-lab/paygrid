<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-way sender only - this app never reads replies from WhatsApp. Targets
 * WAHA's REST API (POST {base}/api/sendText). Swap send() if the provider
 * ever changes; until api_url/api_key are configured it just logs, so the
 * rest of the ticket flow (claim, reminder, transfer, approve) can be built
 * and tested end-to-end before any WA credentials exist.
 */
class WhatsAppNotifier
{
    public function send(string $groupId, string $message): bool
    {
        $apiUrl = rtrim((string) config('paygrid.whatsapp.api_url'), '/');
        $apiKey = config('paygrid.whatsapp.api_key');
        $session = config('paygrid.whatsapp.session', 'default');

        if (! $apiUrl || ! $apiKey) {
            Log::info('paygrid.whatsapp.send_skipped_not_configured', ['group_id' => $groupId, 'message' => $message]);

            return false;
        }

        try {
            Http::timeout(8)
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->post($apiUrl.'/api/sendText', [
                    'session' => $session,
                    'chatId' => $groupId,
                    'text' => $message,
                ])->throw();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('paygrid.whatsapp.send_failed', ['group_id' => $groupId, 'message' => $exception->getMessage()]);

            return false;
        }
    }
}
