<?php

namespace App\Http\Controllers\BotTele;

use App\Http\Controllers\Controller;
use App\Services\BotTele\IpWhitelistBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** WAHA webhook: poll votes on IP Whitelist approval polls (replaces n8n "WAHA - Incoming Message"). */
class WahaWebhookController extends Controller
{
    public function __invoke(Request $request, IpWhitelistBridge $bridge): JsonResponse
    {
        // A vote approves IP access, so the event must carry WAHA's HMAC-SHA512 of the raw body.
        // Fail closed while no key is configured. TODO(verify against real WAHA): header name/algorithm.
        $key = (string) config('bottele.waha.webhook_hmac_key');
        $signature = strtolower((string) $request->header('X-Webhook-Hmac'));
        if ($key === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $key), $signature)) {
            Log::warning('bottele.waha_webhook_rejected', ['has_signature' => $signature !== '', 'headers' => array_keys($request->headers->all())]);
            abort(403);
        }

        try {
            $outcome = $bridge->handleWebhook($request->json()->all());
        } catch (\Throwable $exception) {
            // 200 regardless, so WAHA does not keep redelivering the same vote.
            report($exception);
            $outcome = 'error';
        }

        return response()->json(['ok' => true, 'outcome' => $outcome]);
    }
}
