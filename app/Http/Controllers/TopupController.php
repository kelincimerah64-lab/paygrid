<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Services\TopupService;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
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
            'amount' => ['required', 'integer', 'min:'.$minimumAmount, 'max:'.config('paygrid.topup.maximum_amount')],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $topup = $topups->create($merchant, $data['customer_reference'], (int) $data['amount'], $data['idempotency_key'] ?? null);

        return redirect()->route('topup.status', [$merchant, $topup]);
    }

    public function status(Merchant $merchant, TopupRequest $topupRequest): View
    {
        abort_unless($topupRequest->merchant_id === $merchant->id, 404);
        $this->expireIfNeeded($topupRequest);

        return view('paygrid.topup-status', ['merchant' => $merchant, 'topupRequest' => $topupRequest->refresh()]);
    }

    public function statusJson(Merchant $merchant, TopupRequest $topupRequest): JsonResponse
    {
        abort_unless($topupRequest->merchant_id === $merchant->id, 404);
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
        abort_unless((bool) $topupRequest->qr_string, 404);

        $result = (new SvgWriter())->write(new QrCode(
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
        abort_unless($topupRequest->status === 'expired', 422);

        $newTopup = $topups->create($merchant, (string) $topupRequest->customer_reference, (int) $topupRequest->amount);

        return redirect()->route('topup.status', [$merchant, $newTopup]);
    }

    private function expireIfNeeded(TopupRequest $topupRequest): void
    {
        if ($topupRequest->status === 'pending' && $topupRequest->expires_at && $topupRequest->expires_at->isPast()) {
            $topupRequest->update(['status' => 'expired']);
        }
    }
}
