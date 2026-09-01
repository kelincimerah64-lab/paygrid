<?php

namespace App\Services;

use App\Jobs\RebuildMerchantDailyMetric;
use App\Models\Merchant;
use App\Models\TopupRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionIngestionService
{
    public function __construct(private readonly MetricRollupService $rollups, private readonly FeeService $fees)
    {
    }

    public function ingestGatewayPayload(string $gateway, array $payload, string $dataSource = 'callback'): ?TopupRequest
    {
        $merchant = $this->resolveMerchant($payload);

        if (! $merchant) {
            return null;
        }

        return $this->ingestForMerchant($merchant, $payload, $gateway, $dataSource);
    }

    public function ingestForMerchant(Merchant $merchant, array $payload, ?string $gateway = null, ?string $dataSource = null, bool $deferMetrics = false): TopupRequest
    {
        $gateway = $gateway ?: $merchant->gateway;
        $dataSource = $dataSource ?: $this->defaultDataSource($gateway);
        $normalized = $this->normalize($merchant, $gateway, $dataSource, $payload);

        $request = DB::transaction(function () use ($normalized) {
            $existing = TopupRequest::query()
                ->where('gateway_ref_id', $normalized['gateway_ref_id'])
                ->when(
                    DB::connection()->getDriverName() !== 'sqlite',
                    fn ($query) => $query->lockForUpdate(),
                )
                ->first();

            if (! $existing) {
                return TopupRequest::query()->create($normalized);
            }

            $preservedChecklist = Arr::only($existing->getAttributes(), [
                'is_processed',
                'processed_by_user_id',
                'checked_by_email',
                'checked_by_role',
                'processed_at',
            ]);

            if ($normalized['expires_at'] === null && $existing->expires_at !== null) {
                $normalized['expires_at'] = $existing->expires_at;
            }
            if ($normalized['succeeded_at'] === null && $existing->succeeded_at !== null) {
                $normalized['succeeded_at'] = $existing->succeeded_at;
            }

            $existing->forceFill(array_merge($normalized, $preservedChecklist))->save();

            return $existing->refresh();
        });

        if (! $deferMetrics) {
            $this->rollups->rebuildMerchantDay($merchant, $request->submitted_at ?? now('Asia/Jakarta'), $request->data_source);
        }

        $this->fees->snapshot($merchant, $request);

        return $request;
    }

    private function resolveMerchant(array $payload): ?Merchant
    {
        $merchantId = Arr::get($payload, 'merchant_id')
            ?? Arr::get($payload, 'merchantId')
            ?? Arr::get($payload, 'merchant.id');

        if ($merchantId) {
            return Merchant::query()->where('merchant_id', $merchantId)->first();
        }

        $merchantName = Arr::get($payload, 'merchant_name')
            ?? Arr::get($payload, 'merchantName')
            ?? Arr::get($payload, 'merchant.name');

        if ($merchantName) {
            return Merchant::query()->where('name', $merchantName)->first();
        }

        return null;
    }

    private function normalize(Merchant $merchant, string $gateway, string $dataSource, array $payload): array
    {
        $status = $this->normalizeStatus((string) (
            Arr::get($payload, 'status')
            ?? Arr::get($payload, 'transaction_status')
            ?? Arr::get($payload, 'settlement_status')
            ?? 'pending'
        ));

        $amount = $this->numericAmount(Arr::get($payload, 'amount') ?? Arr::get($payload, 'total_amount') ?? 0);
        $netAmount = $this->numericAmount(Arr::get($payload, 'response.total') ?? Arr::get($payload, 'net_amount') ?? Arr::get($payload, 'net') ?? $amount);
        $feeAmount = $this->numericAmount(Arr::get($payload, 'fee') ?? Arr::get($payload, 'fee_amount') ?? max(0, $amount - $netAmount));

        $submittedAt = $this->timestamp(Arr::get($payload, 'created_at') ?? Arr::get($payload, 'createdAt'))
            ?? $this->timestamp(Arr::get($payload, 'submitted_at') ?? Arr::get($payload, 'submittedAt'))
            ?? $this->timestamp(Arr::get($payload, 'paid_at') ?? Arr::get($payload, 'paidAt'))
            ?? now('Asia/Jakarta');
        $succeededAt = $status === 'success'
            ? ($this->timestamp(Arr::get($payload, 'paid_at') ?? Arr::get($payload, 'paidAt'))
                ?? $this->timestamp(Arr::get($payload, 'success_at') ?? Arr::get($payload, 'successAt'))
                ?? $this->timestamp(Arr::get($payload, 'completed_at') ?? Arr::get($payload, 'completedAt'))
                ?? $this->timestamp(Arr::get($payload, 'settled_at') ?? Arr::get($payload, 'settledAt'))
                ?? ($dataSource === 'callback' ? now('Asia/Jakarta') : $submittedAt))
            : null;

        return [
            'merchant_id' => $merchant->id,
            'gateway' => $gateway,
            'data_source' => $dataSource,
            'payment_id' => $this->stringOrNull(Arr::get($payload, 'payment_id_provider') ?? Arr::get($payload, 'payment_id') ?? Arr::get($payload, 'paymentId')),
            'gateway_ref_id' => $this->gatewayReference($payload),
            'rrn' => $this->stringOrNull(Arr::get($payload, 'rrn') ?? Arr::get($payload, 'RRN')),
            'transaction_id' => $this->stringOrNull(Arr::get($payload, 'ref_id') ?? Arr::get($payload, 'client_reference') ?? Arr::get($payload, 'transaction_id')),
            'status' => $status,
            'amount' => $amount,
            'net_amount' => $netAmount,
            'fee_amount' => $feeAmount,
            'submitted_at' => $submittedAt,
            'succeeded_at' => $succeededAt,
            'callback_received_at' => $dataSource === 'callback' ? now('Asia/Jakarta') : null,
            'expires_at' => $this->timestamp(Arr::get($payload, 'expires_at') ?? Arr::get($payload, 'expired_at')),
            'gateway_payload' => $payload,
        ];
    }

    private function gatewayReference(array $payload): string
    {
        return (string) (
            Arr::get($payload, 'id')
            ?? Arr::get($payload, 'reference')
            ?? Arr::get($payload, 'ref_id')
            ?? Arr::get($payload, 'payment_id_provider')
            ?? Arr::get($payload, 'payment_id')
            ?? Str::uuid()
        );
    }

    private function normalizeStatus(string $status): string
    {
        return match (Str::lower($status)) {
            'success', 'successful', 'paid', 'approved', 'settled' => 'success',
            'expired', 'expire' => 'expired',
            'failed', 'fail', 'rejected', 'reject', 'cancelled', 'canceled' => 'failed',
            default => 'pending',
        };
    }

    private function numericAmount(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return (int) preg_replace('/\D+/', '', (string) $value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;

            return CarbonImmutable::createFromTimestampMs($number > 9999999999 ? $number : $number * 1000, 'Asia/Jakarta');
        }

        return CarbonImmutable::parse((string) $value, 'Asia/Jakarta');
    }

    private function defaultDataSource(string $gateway): string
    {
        return match ($gateway) {
            'alpha' => 'alpha_pull',
            'artageto' => 'artageto_pull',
            'kingspay' => 'kingspay_pull',
            default => 'gateway_pull',
        };
    }
}
