<?php

namespace App\Http\Controllers\BotTele;

use App\Http\Controllers\Controller;
use App\Services\BotTele\BotTeleDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Telegram webhook for the support bot (replaces the n8n Telegram Trigger). */
class WebhookController extends Controller
{
    public function __invoke(Request $request, BotTeleDispatcher $bot): JsonResponse
    {
        // Telegram echoes setWebhook's secret_token in this header. Fail closed when no secret is configured.
        $secret = (string) config('bottele.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            $bot->handle($request->all());
        } catch (\Throwable $exception) {
            // Always 200: a non-2xx makes Telegram redeliver the update, which would duplicate tickets.
            report($exception);
        }

        return response()->json(['ok' => true]);
    }
}
