<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Services\Gateway\GatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class MerchantSettlementService
{
    public function __construct(private readonly GatewayManager $gateways) {}

    public function refresh(Merchant $merchant, array $filters = []): int
    {
        $rows = $this->gateways->for($merchant)->pullSettlements($merchant, $filters);
        $count = 0;

        foreach ($rows as $row) {
            $normalized = $this->normalize($merchant, $row);
            if ($normalized['gateway_merchant_id'] === '' || $normalized['reference'] === '') {
                continue;
            }

            MerchantSettlement::query()->updateOrCreate(
                [
                    'gateway_merchant_id' => $normalized['gateway_merchant_id'],
                    'reference' => $normalized['reference'],
                ],
                $normalized,
            );
            $count++;
        }

        return $count;
    }

    public function normalize(Merchant $merchant, array $row): array
    {
        $batch = (array) ($row['settlement_batch'] ?? $row['batch'] ?? []);
        $totalAmount = $this->number($row['amount'] ?? $row['total_amount'] ?? 0);
        $totalFee = $this->number($row['fee'] ?? $row['total_fee'] ?? 0);
        $netAmount = $this->number($row['net_amount'] ?? $row['net'] ?? $row['settlement_amount'] ?? max(0, $totalAmount - $totalFee));

        return [
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'gateway_merchant_id' => (string) $merchant->merchant_id,
            'reference' => $this->text($row['reference'] ?? $row['settlement_reference'] ?? $row['id'] ?? '', 220),
            'settlement_type' => $this->text($row['settlement_type'] ?? $row['type'] ?? 'QRIS', 80) ?: null,
            'settlement_date' => $this->date($row['settlement_date'] ?? $row['date'] ?? $row['created_at'] ?? $row['processed_at'] ?? null),
            'status' => $this->text($row['status'] ?? '-', 80) ?: null,
            'batch_name' => $this->text($batch['name'] ?? $row['batch_name'] ?? '', 160) ?: null,
            'batch_from' => $this->text($batch['from'] ?? '', 40) ?: null,
            'batch_until' => $this->text($batch['until'] ?? '', 40) ?: null,
            'trx_count' => max(0, (int) $this->number($row['row'] ?? $row['trx_count'] ?? $row['transaction_count'] ?? 0)),
            'total_amount' => $totalAmount,
            'total_fee' => $totalFee,
            'net_amount' => $netAmount,
            'merchant_name' => $this->text($row['merchant_name'] ?? $merchant->name, 220) ?: null,
            'merchant_group_name' => $this->text($row['merchant_group_name'] ?? $merchant->merchant_group_name, 220) ?: null,
            'processed_at' => $this->dateTime($row['processed_at'] ?? $row['approved_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? null),
            'gateway_created_at' => $this->dateTime($row['created_at'] ?? null),
            'gateway_updated_at' => $this->dateTime($row['updated_at'] ?? null),
            'payload' => Arr::undot($row),
            'synced_at' => now(),
        ];
    }

    private function number(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) floor((float) $value));
        }

        return max(0, (int) preg_replace('/[^0-9-]/', '', (string) $value));
    }

    private function text(mixed $value, int $limit): string
    {
        return mb_substr(trim((string) ($value ?? '')), 0, $limit);
    }

    private function date(mixed $value): ?string
    {
        $dateTime = $this->dateTime($value);

        return $dateTime?->toDateString();
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '' || in_array(strtolower($text), ['null', 'undefined', '-'], true)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($text, 'Asia/Jakarta');
        } catch (\Throwable) {
            return null;
        }
    }
}
