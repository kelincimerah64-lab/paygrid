<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnswerWhatsAppAssistantQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WAHA calls this on every incoming-message event for the WhatsApp session.
 * Deliberately narrow: only replies to direct (1:1, "@c.us") chats, never
 * groups - the same session also carries ticket notifications into the
 * handling/approval groups (see WhatsAppNotifier), and this must never start
 * answering questions in those. Gated by paygrid.whatsapp_assistant_enabled
 * so pointing WAHA's webhook here doesn't make the bot live by itself.
 */
class WhatsAppAssistantWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! config('paygrid.whatsapp_assistant_enabled')) {
            return response()->json(['ok' => true, 'skipped' => 'disabled']);
        }

        if ($request->input('event') !== 'message') {
            return response()->json(['ok' => true, 'skipped' => 'not_a_message_event']);
        }

        $payload = (array) $request->input('payload', []);
        $chatId = (string) ($payload['from'] ?? '');
        $body = trim((string) ($payload['body'] ?? ''));
        $fromMe = (bool) ($payload['fromMe'] ?? false);

        if ($fromMe || $body === '' || ! str_ends_with($chatId, '@c.us')) {
            return response()->json(['ok' => true, 'skipped' => 'not_a_direct_message']);
        }

        AnswerWhatsAppAssistantQuestion::dispatch($chatId, $body);

        return response()->json(['ok' => true]);
    }
}
