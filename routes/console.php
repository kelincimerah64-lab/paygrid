<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Merchant;
use App\Models\AgentOnboardingLink;
use App\Jobs\SyncMerchantTransactions;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gateway:sync-transactions {--merchant=} {--from=} {--to=}', function () {
    $query = Merchant::query()
        ->where('approval_status', 'approved')
        ->whereNotNull('merchant_id');

    if ($this->option('merchant')) {
        $query->where(fn ($scope) => $scope
            ->where('slug', $this->option('merchant'))
            ->orWhere('merchant_id', $this->option('merchant')));
    }

    $filters = array_filter([
        'from' => $this->option('from'),
        'to' => $this->option('to'),
    ]);

    $count = 0;

    $query->chunkById(100, function ($merchants) use ($filters, &$count) {
        foreach ($merchants as $merchant) {
            SyncMerchantTransactions::dispatch($merchant->id, $filters);
            $count++;
        }
    });

    $this->info("Dispatched {$count} merchant sync job(s).");
})->purpose('Dispatch background GET polling jobs for approved merchants.');

Artisan::command('gateway:sync-balances {--merchant=}', function (\App\Services\GatewayBalanceService $balances) {
    $query = Merchant::query()
        ->where('approval_status', 'approved')
        ->where('gateway', 'hilogate')
        ->whereNotNull('merchant_id');

    if ($this->option('merchant')) {
        $query->where(fn ($scope) => $scope
            ->where('slug', $this->option('merchant'))
            ->orWhere('merchant_id', $this->option('merchant')));
    }

    $synced = 0;
    $failed = 0;

    $query->chunkById(100, function ($merchants) use ($balances, &$synced, &$failed) {
        foreach ($merchants as $merchant) {
            try {
                $balances->refresh($merchant);
                $synced++;
            } catch (\Throwable $exception) {
                $failed++;
                $this->warn("Balance sync failed for {$merchant->slug}: {$exception->getMessage()}");
            }
        }
    });

    $this->info("Synced {$synced} merchant balance row(s). Failed: {$failed}.");

    return $failed > 0 ? self::FAILURE : self::SUCCESS;
})->purpose('Refresh Hilogate balances into local DB cache.');

Artisan::command('gateway:sync-settlements {--merchant=} {--status=} {--page-size=100}', function (\App\Services\MerchantSettlementService $settlements) {
    $query = Merchant::query()
        ->where('approval_status', 'approved')
        ->where('gateway', 'hilogate')
        ->whereNotNull('merchant_id');

    if ($this->option('merchant')) {
        $query->where(fn ($scope) => $scope
            ->where('slug', $this->option('merchant'))
            ->orWhere('merchant_id', $this->option('merchant')));
    }

    $synced = 0;
    $failed = 0;
    $filters = array_filter([
        'page' => 1,
        'page_size' => $this->option('page-size'),
        'status' => $this->option('status'),
    ], static fn ($value) => $value !== null && $value !== '');

    $query->chunkById(100, function ($merchants) use ($settlements, $filters, &$synced, &$failed) {
        foreach ($merchants as $merchant) {
            try {
                $synced += $settlements->refresh($merchant, $filters);
            } catch (\Throwable $exception) {
                $failed++;
                $this->warn("Settlement sync failed for {$merchant->slug}: {$exception->getMessage()}");
            }
        }
    });

    $this->info("Synced {$synced} settlement row(s). Failed merchant(s): {$failed}.");

    return $failed > 0 ? self::FAILURE : self::SUCCESS;
})->purpose('Refresh Hilogate merchant settlements into local DB cache.');

Artisan::command('onboarding-links:expire', function () {
    $count = AgentOnboardingLink::query()
        ->where('status', 'active')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->update(['status' => 'expired']);

    $this->info("Expired {$count} onboarding link(s).");
})->purpose('Mark expired agent onboarding links as expired.');

Artisan::command('gateway:import-hilogate {merchant_id} {--slug=} {--name=} {--type=cm} {--sync}', function () {
    $merchantId = (string) $this->argument('merchant_id');
    $slug = Str::slug((string) ($this->option('slug') ?: $this->option('name') ?: $merchantId));
    $name = (string) ($this->option('name') ?: $slug);

    $merchant = Merchant::query()->updateOrCreate(
        ['merchant_id' => $merchantId],
        [
            'slug' => $slug,
            'name' => $name,
            'merchant_type' => $this->option('type') === 'script' ? 'script' : 'cm',
            'gateway' => 'hilogate',
            'approval_status' => 'approved',
            'topup_enabled' => $this->option('type') !== 'script',
            'approved_at' => now(),
        ],
    );
    app(\App\Services\AuditLogService::class)->record('merchant.hilogate_imported', $merchant, null, $merchant->only([
        'merchant_id', 'slug', 'name', 'gateway', 'approval_status', 'merchant_type',
    ]));

    if ($this->option('sync')) {
        (new SyncMerchantTransactions($merchant->id))->handle(
            app(\App\Services\Gateway\GatewayManager::class),
            app(\App\Services\TransactionIngestionService::class),
            app(\App\Services\GatewayBalanceService::class),
        );
        $this->info("Synced merchant {$merchant->name} into PayGrid.");
    } else {
        SyncMerchantTransactions::dispatch($merchant->id);
        $this->info("Imported merchant {$merchant->name} and dispatched sync job.");
    }
})->purpose('Import one Hilogate merchant mapping without storing credentials in source.');

Artisan::command('metrics:rebuild-daily {date?}', function (\App\Services\MetricRollupService $rollups) {
    $date = $this->argument('date') ?: now('Asia/Jakarta')->toDateString();
    $count = 0;

    Merchant::query()
        ->where('approval_status', 'approved')
        ->chunkById(100, function ($merchants) use ($rollups, $date, &$count) {
            foreach ($merchants as $merchant) {
                $rollups->rebuildMerchantDay($merchant, $date);
                $count++;
            }
        });

    $this->info("Rebuilt {$count} merchant daily metric row(s) for {$date}.");
})->purpose('Rebuild summary metrics from topup_requests for one date.');

Artisan::command('fees:backfill-snapshots', function (\App\Services\FeeService $fees) {
    $count = 0;
    \App\Models\TopupRequest::query()->with('merchant')->chunkById(500, function ($requests) use ($fees, &$count) {
        foreach ($requests as $request) {
            if ($request->merchant) {
                $fees->snapshot($request->merchant, $request);
                $count++;
            }
        }
    });

    $this->info("Backfilled {$count} fee snapshot(s).");
})->purpose('Create immutable fee snapshots for existing transactions.');

Artisan::command('gateway:health-hilogate {merchant}', function (\App\Services\Gateway\GatewayManager $gateways) {
    $merchant = Merchant::query()
        ->where('slug', $this->argument('merchant'))
        ->orWhere('merchant_id', $this->argument('merchant'))
        ->first();

    if (! $merchant) {
        $this->error('Merchant tidak ditemukan di PayGrid.');
        return self::FAILURE;
    }

    $checks = [
        'merchant_approved' => $merchant->approval_status === 'approved',
        'gateway_hilogate' => $merchant->gateway === 'hilogate',
        'merchant_id_present' => (bool) $merchant->merchant_id,
        'queue_connection' => (bool) config('queue.default'),
    ];

    try {
        $rows = $gateways->for($merchant)->pullTransactions($merchant, ['page' => 1, 'page_size' => 1]);
        $checks['hilogate_get'] = true;
        $checks['response_array'] = is_array($rows);
        $this->line('Latest transaction rows: '.count($rows));
    } catch (\Throwable $exception) {
        $checks['hilogate_get'] = false;
        $checks['response_array'] = false;
        $this->error('Hilogate error: '.$exception->getMessage());
    }

    foreach ($checks as $check => $passed) {
        $this->line(($passed ? '<info>PASS</info> ' : '<error>FAIL</error> ').$check);
    }

    return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
})->purpose('Run a read-only single-merchant Hilogate connectivity and response check.');
