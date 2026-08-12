<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Services\MetricRollupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RebuildMerchantDailyMetric implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public readonly int $merchantId,
        public readonly string $date,
        public readonly ?string $dataSource = null,
    ) {}

    public function handle(MetricRollupService $rollups): void
    {
        $merchant = Merchant::query()->find($this->merchantId);

        if ($merchant) {
            $rollups->rebuildMerchantDay($merchant, $this->date, $this->dataSource);
        }
    }
}
