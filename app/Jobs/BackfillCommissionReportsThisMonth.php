<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * A new commission config applies to the whole current month, not just going
 * forward - but commission:generate-reports only ever processes one day
 * (yesterday) via its daily 01:00 schedule. Without this, every config
 * created after the 1st silently misses the days already elapsed that month.
 */
class BackfillCommissionReportsThisMonth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $todayDate = now('Asia/Jakarta')->toDateString();
        $monthStart = CarbonImmutable::parse($todayDate, 'Asia/Jakarta')->startOfMonth();
        $yesterday = CarbonImmutable::parse($todayDate, 'Asia/Jakarta')->subDay();

        for ($date = $monthStart; $date->lte($yesterday); $date = $date->addDay()) {
            Artisan::call('commission:generate-reports', ['date' => $date->toDateString()]);
        }
    }
}
