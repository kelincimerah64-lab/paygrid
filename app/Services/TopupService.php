<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Services\Gateway\GatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TopupService
{
    public function __construct(private readonly GatewayManager $gateways) {}

    public function create(Merchant $merchant, string $customerReference, int $amount, ?string $idempotencyKey = null): TopupRequest
    {
        $idempotencyKey ??= (string) Str::uuid();
        $existing = TopupRequest::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        $request = DB::transaction(fn () => TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'customer_reference' => $customerReference,
            'idempotency_key' => $idempotencyKey,
            'gateway' => $merchant->gateway,
            'data_source' => 'public_submit',
            'status' => 'pending',
            'amount' => $amount,
            'submitted_at' => now(),
            'expires_at' => now()->addMinutes(config('paygrid.topup.expires_in_minutes', 30)),
        ]));

        app(AuditLogService::class)->record('topup.created', $request, null, $request->only([
            'merchant_id', 'customer_reference', 'amount', 'gateway', 'status',
        ]));

        try {
            $response = $this->gateways->for($merchant)->createQrisTransaction(
                $merchant,
                $request->idempotency_key,
                $amount,
                (int) config('paygrid.topup.expires_in_minutes', 30),
            );
            $data = (array) ($response['data'] ?? []);
            $nested = (array) ($data['data'] ?? []);
            $request->update([
                'gateway_ref_id' => (string) ($data['id'] ?? $data['reference'] ?? $request->idempotency_key),
                'qr_string' => $nested['qr_string'] ?? $data['qr_string'] ?? $data['qris'] ?? null,
                'payment_url' => $nested['qr_url'] ?? $data['qr_url'] ?? $data['payment_url'] ?? null,
                'gateway_status' => $data['status'] ?? $response['status'] ?? 'pending',
                'gateway_payload' => $response,
            ]);
        } catch (\Throwable $exception) {
            $request->update(['status' => 'failed', 'gateway_payload' => ['error' => $exception->getMessage()]]);
            throw $exception;
        }

        return $request->refresh();
    }
}
