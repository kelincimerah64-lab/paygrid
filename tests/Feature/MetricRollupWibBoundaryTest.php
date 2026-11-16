<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantDailyMetric;
use App\Models\TopupRequest;
use App\Services\MetricRollupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricRollupWibBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transaction_submitted_early_morning_wib_rolls_up_under_the_correct_wib_day_not_the_utc_day(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();

        // 02:00 WIB on the 20th - stored as 19:00 UTC on the 19th. A UTC-date
        // rollup for "the 20th" must still pick this up; a rollup for "the
        // 19th" must not.
        $wibDay = CarbonImmutable::parse('2026-10-20', 'Asia/Jakarta');
        $earlyMorningWib = $wibDay->startOfDay()->addHours(2);
        TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'rollup-boundary-ref',
            'transaction_id' => 'rollup-boundary-trx',
            'status' => 'success',
            'amount' => 120000,
            'net_amount' => 118800,
            'fee_amount' => 1200,
            'customer_reference' => 'ROLLUP-BOUNDARY-REF',
            'submitted_at' => $earlyMorningWib->utc(),
        ]);

        $rollups = app(MetricRollupService::class);
        $metricForThe20th = $rollups->rebuildMerchantDay($merchant, $wibDay, 'gateway_pull');
        $metricForThe19th = $rollups->rebuildMerchantDay($merchant, $wibDay->subDay(), 'gateway_pull');

        $this->assertSame('2026-10-20', $metricForThe20th->metric_date->toDateString());
        $this->assertSame(1, $metricForThe20th->trx_success);
        $this->assertSame(120000, $metricForThe20th->amount_success);

        $this->assertSame(0, $metricForThe19th->trx_success);
        $this->assertSame(0, $metricForThe19th->amount_success);
    }
}
