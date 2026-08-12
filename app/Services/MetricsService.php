<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantDailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MetricsService
{
    public function monthToDateRange(): array
    {
        $now = CarbonImmutable::now('Asia/Jakarta');

        return [$now->startOfMonth()->startOfDay(), $now];
    }

    public function merchantSummary(Merchant $merchant, mixed $from = null, mixed $to = null): array
    {
        $query = $this->metricRange(MerchantDailyMetric::query()->where('merchant_id', $merchant->id), $from, $to);

        return [
            'total' => (int) $query->clone()->sum('trx_total'),
            'success' => (int) $query->clone()->sum('trx_success'),
            'pending' => (int) $query->clone()->sum('trx_pending'),
            'expired' => (int) $query->clone()->sum('trx_expired'),
            'volume_success' => (int) $query->clone()->sum('amount_success'),
            'settlement' => (int) $query->clone()->sum('settled_total'),
        ];
    }

    public function agentMerchants(Agent $agent, mixed $from = null, mixed $to = null): Collection
    {
        return Merchant::query()
            ->where('agent_id', $agent->id)
            ->where('approval_status', 'approved')
            ->withSum(['metrics as metric_trx_total' => fn (Builder $query) => $this->metricRange($query, $from, $to)], 'trx_total')
            ->withSum(['metrics as metric_volume_success' => fn (Builder $query) => $this->metricRange($query, $from, $to)], 'amount_success')
            ->orderByDesc('metric_trx_total')
            ->orderByDesc('metric_volume_success')
            ->orderBy('name')
            ->get();
    }

    public function maMerchants(mixed $from = null, mixed $to = null): Collection
    {
        return Merchant::query()
            ->where('approval_status', 'approved')
            ->with('agent')
            ->withSum(['metrics as metric_trx_total' => fn (Builder $query) => $this->metricRange($query, $from, $to)], 'trx_total')
            ->withSum(['metrics as metric_volume_success' => fn (Builder $query) => $this->metricRange($query, $from, $to)], 'amount_success')
            ->orderByRaw('COALESCE(metric_trx_total, 0) = 0')
            ->orderByDesc('metric_trx_total')
            ->orderByDesc('metric_volume_success')
            ->orderBy('name')
            ->get();
    }

    private function metricRange(Builder $query, mixed $from = null, mixed $to = null): Builder
    {
        if ($from === null && $to === null) {
            [$from, $to] = $this->monthToDateRange();
        }

        if ($from) {
            $query->whereDate('metric_date', '>=', CarbonImmutable::parse($from)->toDateString());
        }

        if ($to) {
            $query->whereDate('metric_date', '<=', CarbonImmutable::parse($to)->toDateString());
        }

        return $query;
    }
}
