<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Services\Gateway\GatewayManager;
use App\Services\TransactionIngestionService;
use App\Services\TopupService;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TopupController extends Controller
{
    public function store(Request $request, Merchant $merchant, TopupService $topups): RedirectResponse
    {
        abort_unless($merchant->topup_enabled, 404);
        $minimumAmount = $merchant->minimumTopupAmount();
        $data = $request->validate([
            'customer_reference' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'integer', 'min:'.$minimumAmount],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $topup = $topups->create($merchant, $data['customer_reference'], (int) $data['amount'], $data['idempotency_key'] ?? null);

        return redirect()->route('topup.status', [$merchant, $topup->public_token]);
    }

    public function status(Merchant $merchant, TopupRequest $topupRequest, GatewayManager $gateways, TransactionIngestionService $ingestion): View
    {
        abort_unless($topupRequest->merchant_id === $merchant->id, 404);
        $this->ensurePublicToken($topupRequest);
        $this->ensureExpiry($topupRequest);
        $this->refreshGatewayStatus($merchant, $topupRequest, $gateways, $ingestion);
        $this->expireIfNeeded($topupRequest);

        return view('paygrid.topup-status', ['merchant' => $merchant, 'topupRequest' => $topupRequest->refresh()]);
    }

    public function statusJson(Merchant $merchant, TopupRequest $topupRequest, GatewayManager $gateways, TransactionIngestionService $ingestion): JsonResponse
    {
        abort_unless($topupRequest->merchant_id === $merchant->id, 404);
        $this->ensurePublicToken($topupRequest);
        $this->ensureExpiry($topupRequest);
        $this->refreshGatewayStatus($merchant, $topupRequest, $gateways, $ingestion);
        $this->expireIfNeeded($topupRequest);
        $topupRequest->refresh();

        return response()->json([
            'status' => $topupRequest->status,
            'gateway_status' => $topupRequest->gateway_status,
            'qr_string' => $topupRequest->qr_string,
            'payment_url' => $topupRequest->payment_url,
            'amount' => $topupRequest->amount,
            'expires_at' => $topupRequest->expires_at?->toIso8601String(),
        ]);
    }

    public function qr(Merchant $merchant, TopupRequest $topupRequest): Response
    {
        abort_unless($topupRequest->merchant_id === $merchant->id, 404);
        $this->ensurePublicToken($topupRequest);
        abort_unless((bool) $topupRequest->qr_string, 404);

        $result = (new PngWriter())->write(new QrCode(
            data: $topupRequest->qr_string,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 280,
            margin: 12,
        ));

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function regenerate(Merchant $merchant, TopupRequest $topupRequest, TopupService $topups): RedirectResponse
    {
        abort_unless($topupRequest->merchant_id === $merchant->id, 404);
        $this->ensurePublicToken($topupRequest);
        abort_unless($topupRequest->status === 'expired', 422);

        $newTopup = $topups->create($merchant, (string) $topupRequest->customer_reference, (int) $topupRequest->amount);

        return redirect()->route('topup.status', [$merchant, $newTopup->public_token]);
    }

    private function ensurePublicToken(TopupRequest $topupRequest): void
    {
        if (! $topupRequest->public_token) {
            $topupRequest->forceFill(['public_token' => (string) \Illuminate\Support\Str::uuid()])->save();
        }
    }

    private function expireIfNeeded(TopupRequest $topupRequest): void
    {
        if ($topupRequest->status === 'pending' && $topupRequest->expires_at && $topupRequest->expires_at->isPast()) {
            $topupRequest->update(['status' => 'expired']);
        }
    }

    private function ensureExpiry(TopupRequest $topupRequest): void
    {
        if ($topupRequest->expires_at || ! in_array($topupRequest->status, ['pending', 'expired'], true)) {
            return;
        }

        $base = $topupRequest->created_at ?: $topupRequest->submitted_at;
        if (! $base) {
            return;
        }

        $topupRequest->forceFill([
            'expires_at' => $base->copy()->addMinutes((int) config('paygrid.topup.expires_in_minutes', 30)),
        ])->save();
    }

    private function refreshGatewayStatus(Merchant $merchant, TopupRequest $topupRequest, GatewayManager $gateways, TransactionIngestionService $ingestion): void
    {
        if (! in_array($topupRequest->status, ['pending', 'expired'], true) || ! $topupRequest->gateway_ref_id) {
            return;
        }

        if ($topupRequest->last_synced_at && $topupRequest->last_synced_at->gt(now()->subSecond())) {
            return;
        }

        try {
            $response = $gateways->for($merchant)->getTransaction($merchant, $topupRequest->gateway_ref_id);
            $payload = $response['data'] ?? $response;
            if (is_array($payload)) {
                $ingestion->ingestForMerchant($merchant, $payload, $merchant->gateway, $merchant->gateway.'_status_pull');
            }
        } catch (\Throwable) {
            // Keep public status responsive; background sync will retry gateway status checks.
        } finally {
            $topupRequest->forceFill(['last_synced_at' => now()])->save();
            $topupRequest->refresh();
        }
    }
}
