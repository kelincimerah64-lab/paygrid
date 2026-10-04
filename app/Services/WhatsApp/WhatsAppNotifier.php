<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-way sender only - this app never reads replies from WhatsApp. Swap the
 * body of send() for a real gateway call (Wablas/Fonnte/WAHA) once credentials
 * exist; until then it just logs, so the rest of the ticket flow (claim,
 * reminder, transfer notifications) can be built and tested end-to-end first.
 */
class WhatsAppNotifier
{
    public function send(string $groupId, string $message): bool
    {
        $apiUrl = config('paygrid.whatsapp.api_url');
        $apiKey = config('paygrid.whatsapp.api_key');

        if (! $apiUrl || ! $apiKey) {
            Log::info('paygrid.whatsapp.send_skipped_not_configured', ['group_id' => $groupId, 'message' => $message]);

            return false;
        }

        try {
            Http::timeout(8)->withToken($apiKey)->post($apiUrl, [
                'target' => $groupId,
                'message' => $message,
            ])->throw();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('paygrid.whatsapp.send_failed', ['group_id' => $groupId, 'message' => $exception->getMessage()]);

            return false;
        }
    }
}
