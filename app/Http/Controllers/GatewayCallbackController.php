<?php

namespace App\Http\Controllers;

use App\Models\GatewaySyncLog;
use App\Models\Merchant;
use App\Services\Gateway\GatewayCallbackSignatureService;
use App\Services\TransactionIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class GatewayCallbackController extends Controller
{
    public function receive(Request $request, string $gateway, string $type, TransactionIngestionService $ingestion, GatewayCallbackSignatureService $signatures): JsonResponse
    {
        $startedAt = now();
        $payload = $request->all();
        $merchantId = Arr::get($payload, 'merchant_id')
            ?? Arr::get($payload, 'merchantId')
            ?? Arr::get($payload, 'merchant.id');
        $merchant = $merchantId ? Merchant::query()->where('merchant_id', $merchantId)->first() : null;

        if (! $signatures->verify($request, $gateway, $payload, $merchant)) {
            GatewaySyncLog::query()->create([
                'merchant_id' => $merchant?->id,
                'gateway' => $gateway,
                'direction' => 'callback',
                'endpoint' => $request->path(),
                'http_status' => 401,
                'status' => 'rejected_signature',
                'message' => 'Callback signature or trusted IP validation failed.',
                'request_meta' => ['type' => $type, 'ip' => $request->ip(), 'keys' => array_keys($payload)],
                'response_meta' => [],
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            return response()->json(['ok' => false, 'message' => 'invalid_callback_signature'], 401);
        }

        $topupRequest = $type === 'withdrawal'
            ? null
            : $ingestion->ingestGatewayPayload($gateway, $payload, 'callback');

        GatewaySyncLog::query()->create([
            'merchant_id' => $topupRequest?->merchant_id,
            'gateway' => $gateway,
            'direction' => 'callback',
            'endpoint' => $request->path(),
            'http_status' => $topupRequest || $type === 'withdrawal' ? 202 : 422,
            'status' => $topupRequest || $type === 'withdrawal' ? 'accepted' : 'unmatched_merchant',
            'message' => $topupRequest
                ? 'Callback upserted into topup_requests and daily metrics.'
                : 'Callback accepted, but merchant could not be resolved from payload.',
            'request_meta' => [
                'type' => $type,
                'ip' => $request->ip(),
                'keys' => array_keys($payload),
            ],
            'response_meta' => [
                'topup_request_id' => $topupRequest?->id,
                'gateway_ref_id' => $topupRequest?->gateway_ref_id,
            ],
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => $topupRequest ? 'accepted' : 'accepted_unmatched_merchant',
            'topup_request_id' => $topupRequest?->id,
        ], 202);
    }
}
