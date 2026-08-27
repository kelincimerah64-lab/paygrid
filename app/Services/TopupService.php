<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Services\Gateway\GatewayManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TopupService
{
    public function __construct(private readonly GatewayManager $gateways) {}

    public function create(Merchant $merchant, string $customerReference, int $amount, ?string $idempotencyKey = null): TopupRequest
    {
        $idempotencyKey ??= (string) Str::uuid();
        $existing = $this->findExisting($merchant, $idempotencyKey, $amount, $customerReference);

        if ($existing) {
            return $existing;
        }

        try {
            $request = DB::transaction(fn () => TopupRequest::query()->create([
                'merchant_id' => $merchant->id,
                'customer_reference' => $customerReference,
                'idempotency_key' => $idempotencyKey,
                'public_token' => (string) Str::uuid(),
                'gateway' => $merchant->gateway,
                'data_source' => 'public_submit',
                'status' => 'pending',
                'amount' => $amount,
                'submitted_at' => now(),
                'expires_at' => now()->addMinutes(config('paygrid.topup.expires_in_minutes', 30)),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findExisting($merchant, $idempotencyKey, $amount, $customerReference);

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }

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
            throw ValidationException::withMessages([
                'amount' => $this->gatewayFailureMessage($exception),
            ]);
        }

        return $request->refresh();
    }

    private function findExisting(Merchant $merchant, string $idempotencyKey, int $amount, string $customerReference): ?TopupRequest
    {
        $existing = TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        if ((int) $existing->amount !== $amount || (string) $existing->customer_reference !== $customerReference) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Idempotency key sudah dipakai untuk nominal atau reference berbeda.',
            ]);
        }

        return $existing;
    }

    private function gatewayFailureMessage(\Throwable $exception): string
    {
        if ($exception instanceof RequestException && $exception->response) {
            $payload = $exception->response->json();
            $message = is_array($payload)
                ? ($payload['message'] ?? $payload['error'] ?? $payload['errors']['amount'][0] ?? null)
                : null;

            if (is_string($message) && trim($message) !== '') {
                return 'Topup ditolak gateway: '.trim($message);
            }
        }

        return 'Topup belum bisa dibuat di gateway. Silakan cek nominal dan coba lagi.';
    }
}
