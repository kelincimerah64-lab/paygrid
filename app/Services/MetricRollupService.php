<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantDailyMetric;
use App\Models\TopupRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class MetricRollupService
{
    public function rebuildMerchantDay(Merchant $merchant, CarbonInterface|string $date, ?string $dataSource = null): MerchantDailyMetric
    {
        $metricDate = $date instanceof CarbonInterface
            ? CarbonImmutable::instance($date)->startOfDay()
            : CarbonImmutable::parse((string) $date, 'Asia/Jakarta')->startOfDay();
        $day = $metricDate->toDateString();

        $query = TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->whereDate('submitted_at', $day);

        if ($dataSource !== null) {
            $query->where('data_source', $dataSource);
        }

        $rows = $query
            ->selectRaw('COUNT(*) as trx_total')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as trx_success")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND is_processed = 1 THEN 1 ELSE 0 END) as trx_success_processed")
            ->selectRaw("SUM(CASE WHEN status = 'success' AND is_processed = 0 THEN 1 ELSE 0 END) as trx_success_unprocessed")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as trx_pending")
            ->selectRaw("SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN 1 ELSE 0 END) as trx_expired")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as amount_success")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' AND is_processed = 1 THEN amount ELSE 0 END), 0) as amount_success_processed")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' AND is_processed = 0 THEN amount ELSE 0 END), 0) as amount_success_unprocessed")
            ->selectRaw('COALESCE(SUM(amount), 0) as amount_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as amount_pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('expired', 'failed', 'rejected') THEN amount ELSE 0 END), 0) as amount_expired")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'success' THEN net_amount ELSE 0 END), 0) as net_success")
            ->selectRaw('COALESCE(SUM(fee_amount), 0) as fee_total')
            ->first();

        $source = $dataSource ?? $this->defaultDataSource($merchant);

        return MerchantDailyMetric::query()->updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'metric_date' => $metricDate,
                'data_source' => $source,
            ],
            [
                'agent_id' => $merchant->agent_id,
                'gateway' => $merchant->gateway,
                'trx_total' => (int) $rows->trx_total,
                'trx_success' => (int) $rows->trx_success,
                'trx_success_processed' => (int) $rows->trx_success_processed,
                'trx_success_unprocessed' => (int) $rows->trx_success_unprocessed,
                'trx_pending' => (int) $rows->trx_pending,
                'trx_expired' => (int) $rows->trx_expired,
                'amount_success' => (int) $rows->amount_success,
                'amount_success_processed' => (int) $rows->amount_success_processed,
                'amount_success_unprocessed' => (int) $rows->amount_success_unprocessed,
                'amount_total' => (int) $rows->amount_total,
                'amount_pending' => (int) $rows->amount_pending,
                'amount_expired' => (int) $rows->amount_expired,
                'net_success' => (int) $rows->net_success,
                'fee_total' => (int) $rows->fee_total,
                'settled_total' => (int) $rows->net_success,
                'ticket_total' => $merchant->tickets()->whereDate('created_at', $day)->count(),
            ],
        );
    }

    private function defaultDataSource(Merchant $merchant): string
    {
        return match ($merchant->gateway) {
            'alpha' => 'alpha_pull',
            'artageto' => 'artageto_pull',
            'kingspay' => 'kingspay_pull',
            default => 'gateway_pull',
        };
    }
}
