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

Artisan::command('onboarding:universal-link', function () {
    $link = AgentOnboardingLink::query()
        ->whereNull('agent_id')
        ->where('kind', 'universal')
        ->where('status', 'active')
        ->first();

    if (! $link) {
        $link = AgentOnboardingLink::query()->create([
            'agent_id' => null,
            'kind' => 'universal',
            'token' => Str::random(20),
            'status' => 'active',
            'reusable' => true,
            'expires_at' => null,
        ]);
        $this->info('Created new universal onboarding link.');
    } else {
        $this->info('Universal onboarding link already exists.');
    }

    $this->line(route('merchant-registration.token-form', $link));
})->purpose('Print the single hidden universal onboarding link, creating it if it does not exist yet.');

Artisan::command('gateway:sync-transactions {--merchant=} {--from=} {--to=} {--max-pages=} {--page-size=} {--queue=default}', function (\App\Services\GatewaySyncDispatcher $dispatcher) {
    // The schedule itself always ticks every 5s (Laravel can't read a live DB
    // value into a cron-style definition) - this self-throttle is what makes
    // the effective poll interval adjustable via system_settings without a
    // redeploy. Ticks that come in before the configured interval has
    // elapsed since the last real dispatch cycle are a no-op.
    $minInterval = max(5, (int) \App\Models\PaygridSetting::value('gateway_sync_min_interval_seconds', '5'));
    $lastCycleKey = 'paygrid:gateway-sync:last-cycle-at';
    // Stored as a plain integer timestamp, not a Carbon object -
    // config('cache.serializable_classes') is false (Laravel's default
    // object-injection hardening), so any cached object unserializes back
    // as __PHP_Incomplete_Class instead of a real Carbon instance.
    $lastCycle = \Illuminate\Support\Facades\Cache::get($lastCycleKey);

    if ($lastCycle && now()->timestamp - (int) $lastCycle < $minInterval) {
        return;
    }

    \Illuminate\Support\Facades\Cache::put($lastCycleKey, now()->timestamp, now()->addMinutes(10));

    $query = Merchant::query()
        ->where('approval_status', 'approved')
        ->whereIn('gateway', ['hilogate', 'artageto', 'magnetopay'])
        ->whereNotNull('merchant_id')
        ->whereNotNull('merchant_key')
        ->where(fn ($scope) => $scope
            ->where(fn ($nested) => $nested->where('merchant_type', 'cm')->where('topup_enabled', true))
            ->orWhere('merchant_type', 'script'));

    if ($this->option('merchant')) {
        $query->where(fn ($scope) => $scope
            ->where('slug', $this->option('merchant'))
            ->orWhere('merchant_id', $this->option('merchant')));
    }

    $filters = array_filter([
        'from' => $this->option('from'),
        'to' => $this->option('to'),
        'max_pages' => $this->option('max-pages'),
        'page_size' => $this->option('page-size'),
    ]);

    $count = 0;

    $queue = (string) $this->option('queue');

    $query->chunkById(100, function ($merchants) use ($dispatcher, $filters, $queue, &$count) {
        foreach ($merchants as $merchant) {
            $merchantFilters = $filters;
            if ($override = $merchant->onboarding_payload['pull_mode_override'] ?? null) {
                $merchantFilters['pull_mode'] = $override;
            }
            if ($dispatcher->dispatch($merchant->id, $merchantFilters, $queue)) {
                $count++;
            }
        }
    });

    $this->info("Dispatched {$count} merchant sync job(s) to {$queue}; skipped merchants with active sync lock.");
})->purpose('Dispatch background GET polling jobs for approved merchants.');

Artisan::command('gateway:sync-withdrawals {--merchant=}', function () {
    $query = Merchant::query()
        ->where('approval_status', 'approved')
        ->whereIn('gateway', ['hilogate', 'magnetopay'])
        ->whereNotNull('merchant_id')
        ->whereNotNull('merchant_key');

    if ($this->option('merchant')) {
        $query->where(fn ($scope) => $scope
            ->where('slug', $this->option('merchant'))
            ->orWhere('merchant_id', $this->option('merchant')));
    }

    $count = 0;

    $query->chunkById(100, function ($merchants) use (&$count) {
        foreach ($merchants as $merchant) {
            \App\Jobs\SyncMerchantWithdrawals::dispatch($merchant->id);
            $count++;
        }
    });

    $this->info("Dispatched {$count} merchant withdrawal sync job(s).");
})->purpose('Dispatch background sync jobs pulling Hilogate withdrawal history into merchant_withdrawals.');

Artisan::command('reconciliation:monthly {--month=}', function () {
    $month = $this->option('month') ?: now('Asia/Jakarta')->subMonthNoOverflow()->format('Y-m');

    $merchants = Merchant::query()
        ->where('approval_status', 'approved')
        ->whereIn('gateway', ['hilogate', 'artageto', 'magnetopay'])
        ->whereNotNull('merchant_id')
        ->whereNotNull('merchant_key')
        ->get(['id', 'gateway']);

    $collectionCount = 0;
    $withdrawalCount = 0;

    foreach ($merchants as $merchant) {
        \App\Jobs\ReconcileMerchantMonth::dispatch($merchant->id, $month)->onQueue('backfill');
        $collectionCount++;

        if (in_array($merchant->gateway, ['hilogate', 'magnetopay'], true)) {
            \App\Jobs\ReconcileMerchantMonthWithdrawals::dispatch($merchant->id, $month)->onQueue('backfill');
            $withdrawalCount++;
        }
    }

    $this->info("Dispatched reconciliation for {$month}: {$collectionCount} collection job(s), {$withdrawalCount} withdrawal job(s).");
})->purpose('Walk every approved merchant\'s full given month (default: last month) against the gateway to catch anything the daily sync missed.');

Artisan::command('reconciliation:sweep-stale {--minutes=15}', function () {
    $minutes = (int) $this->option('minutes');
    $staleBefore = now()->subMinutes($minutes);

    // A worker restart mid-job (deploy, OOM, etc.) can kill a job without
    // Laravel ever calling its failed() handler - the run row is left at
    // status=running forever with no further updates. Nothing else revisits
    // it, so without this sweep it just sits there indefinitely (see
    // Tiktok5000/2026-11-12 incident). A run still genuinely progressing
    // saves a checkpoint after every page, so one truly stuck this long is
    // safe to call orphaned rather than slow.
    $stale = \App\Models\ReconciliationRun::query()
        ->where('status', 'running')
        ->where('updated_at', '<', $staleBefore)
        ->get();

    foreach ($stale as $run) {
        $run->update([
            'status' => 'failed',
            'last_error' => "Orphaned - tidak ada progress selama lebih dari {$minutes} menit (kemungkinan worker queue restart di tengah proses). Coba dispatch ulang manual.",
        ]);
    }

    $this->info("Marked {$stale->count()} stale reconciliation run(s) as failed.");
})->purpose('Mark reconciliation runs stuck at "running" with no progress as failed instead of leaving them stuck forever.');

Artisan::command('gateway:sync-balances {--merchant=}', function (\App\Services\GatewayBalanceService $balances) {
    $query = Merchant::query()
        ->where('approval_status', 'approved')
        ->whereIn('gateway', ['hilogate', 'artageto', 'magnetopay'])
        ->whereNotNull('merchant_id')
        ->whereNotNull('merchant_key');

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
        ->whereIn('gateway', ['hilogate', 'magnetopay'])
        ->whereNotNull('merchant_id')
        ->whereNotNull('merchant_key');

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
            app(\App\Services\GatewaySyncDispatcher::class),
        );
        $this->info("Synced merchant {$merchant->name} into PayGrid.");
    } else {
        app(\App\Services\GatewaySyncDispatcher::class)->dispatch($merchant->id);
        $this->info("Imported merchant {$merchant->name} and dispatched sync job.");
    }
})->purpose('Import one Hilogate merchant mapping without storing credentials in source.');

Artisan::command('paygrid:queue-monitor', function () {
    $oldestJob = \Illuminate\Support\Facades\DB::table('jobs')
        ->whereIn('queue', ['default', 'live', 'backfill'])
        ->whereNull('reserved_at')
        ->where('available_at', '<=', time())
        ->min('created_at');
    $queueLag = $oldestJob ? max(0, time() - (int) $oldestJob) : 0;
    $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')
        ->where('failed_at', '>=', now()->subMinutes(15))
        ->where('exception', 'not like', '%SQLSTATE[40001]%')
        ->where('exception', 'not like', '%Deadlock found%')
        ->count();
    $latestSync = \App\Models\GatewaySyncLog::query()->where('direction', 'pull')->latest('finished_at')->first();
    $latestCallback = \App\Models\TopupRequest::query()->whereNotNull('callback_received_at')->latest('callback_received_at')->first();
    $syncLag = $latestSync?->finished_at ? now()->diffInSeconds($latestSync->finished_at) : null;
    $callbackLag = $latestCallback?->callback_received_at ? now()->diffInSeconds($latestCallback->callback_received_at) : null;

    $this->line('queue_lag_seconds=' . $queueLag);
    $this->line('failed_jobs=' . $failedJobs);
    $this->line('sync_lag_seconds=' . ($syncLag ?? 'none'));
    $this->line('callback_lag_seconds=' . ($callbackLag ?? 'none'));

    if ($queueLag > 120 || $failedJobs > 0 || $syncLag === null || $syncLag > 180) {
        \Illuminate\Support\Facades\Log::warning('paygrid.queue_monitor.alert', compact('queueLag', 'failedJobs', 'syncLag', 'callbackLag'));
    }

    return ($queueLag > 300 || $failedJobs > 0) ? self::FAILURE : self::SUCCESS;
})->purpose('Report queue lag, failed jobs, sync lag, and callback lag.');

Artisan::command('paygrid:maintenance-prune', function () {
    $successHours = max(1, (int) config('paygrid.gateway_sync.success_log_retention_hours', 6));
    $failedDays = max(1, (int) config('paygrid.gateway_sync.failed_log_retention_days', 14));
    $failedJobDays = max(1, (int) config('paygrid.gateway_sync.failed_job_retention_days', 14));

    // Delete in bounded batches (not a single unbounded DELETE) so a large backlog
    // can't turn one prune run into one long-running, lock-heavy statement.
    $deleteInBatches = function ($query, int $batchSize = 5000, int $maxBatches = 500): int {
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $affected = (clone $query)->limit($batchSize)->delete();
            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
        }

        return $deleted;
    };

    // No `direction` filter here on purpose: 'pull', 'backfill', and 'callback' rows
    // all count toward the same retention window -- a prior version of this command
    // only matched direction='pull', which silently let ~3M 'backfill' rows accumulate
    // forever (they're the vast majority of writes to this table).
    $deletedSuccessLogs = $deleteInBatches(
        \App\Models\GatewaySyncLog::query()
            ->where('status', 'success')
            ->where('created_at', '<', now()->subHours($successHours))
    );

    $deletedFailedLogs = $deleteInBatches(
        \App\Models\GatewaySyncLog::query()
            ->where('status', 'failed')
            ->where('created_at', '<', now()->subDays($failedDays))
    );

    $deletedFailedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')
        ->where('failed_at', '<', now()->subDays($failedJobDays))
        ->delete();

    $deletedDeadlockJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')
        ->where('failed_at', '<', now()->subMinutes(5))
        ->where(fn ($query) => $query
            ->where('exception', 'like', '%SQLSTATE[40001]%')
            ->orWhere('exception', 'like', '%Deadlock found%'))
        ->delete();

    $this->info("Pruned {$deletedSuccessLogs} success sync log(s), {$deletedFailedLogs} failed sync log(s), {$deletedFailedJobs} failed job(s), and {$deletedDeadlockJobs} transient deadlock job(s).");
})->purpose('Prune high-volume operational PayGrid logs without touching transaction data.');

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

/**
 * Computed for the day that just ended (default: yesterday WIB) and
 * reported the next day, matching the Commission Configuration page's
 * "report day = transaction day + 1" rule. Only 'active' configs run -
 * drafts never generate real commission numbers. Safe to re-run: the
 * unique (merchant_id, transaction_date) key on merchant_commission_reports
 * makes this an upsert, not a duplicate-insert.
 */
Artisan::command('commission:generate-reports {date?}', function () {
    $date = $this->argument('date') ?: now('Asia/Jakarta')->subDay()->toDateString();
    $dayOfWeek = \Carbon\CarbonImmutable::parse($date, 'Asia/Jakarta')->dayOfWeek;
    $rangeStart = \Carbon\CarbonImmutable::parse($date, 'Asia/Jakarta')->startOfDay()->utc();
    $rangeEnd = \Carbon\CarbonImmutable::parse($date, 'Asia/Jakarta')->endOfDay()->utc();

    $configs = \App\Models\CommissionConfig::query()
        ->where('status', 'active')
        ->with(['days' => fn ($q) => $q->where('day_of_week', $dayOfWeek)->where('enabled', true), 'merchants'])
        ->get()
        ->filter(fn ($c) => $c->days->isNotEmpty());

    $count = 0;
    foreach ($configs as $config) {
        $rate = $config->days->first()->rate_percent;
        $wdFeeFixed = $config->days->first()->wd_fee_fixed;
        $merchantIds = match (true) {
            $config->apply_scope === 'specific' => $config->merchants->pluck('id'),
            $config->agent_id !== null => Merchant::query()->where('agent_id', $config->agent_id)->pluck('id'),
            default => Merchant::query()->whereRelation('agent', 'ma_user_id', $config->ma_user_id)->pluck('id'),
        };

        foreach ($merchantIds as $merchantId) {
            $volume = (int) \App\Models\TopupRequest::query()
                ->where('merchant_id', $merchantId)
                ->where('status', 'success')
                ->whereBetween('submitted_at', [$rangeStart, $rangeEnd])
                ->sum('amount');

            $wdQuery = \App\Models\MerchantWithdrawal::query()
                ->where('merchant_id', $merchantId)
                ->where('status', 'COMPLETED')
                ->whereBetween('gateway_created_at', [$rangeStart, $rangeEnd]);
            $wdVolume = (int) (clone $wdQuery)->sum('amount');
            $wdTrxCount = (clone $wdQuery)->count();

            \App\Models\MerchantCommissionReport::query()->updateOrCreate(
                ['merchant_id' => $merchantId, 'transaction_date' => $date],
                [
                    'commission_config_id' => $config->id,
                    'day_of_week' => $dayOfWeek,
                    'volume' => $volume,
                    'rate_percent' => $rate,
                    'commission_amount' => (int) round($volume * $rate / 100),
                    'wd_volume' => $wdVolume,
                    'wd_trx_count' => $wdTrxCount,
                    'wd_fee_fixed' => $wdFeeFixed,
                    // Withdrawal fee is always a flat IDR amount per transaction,
                    // never a percentage of volume - confirmed with the business.
                    'wd_commission_amount' => (int) round($wdFeeFixed * $wdTrxCount),
                    'report_date' => \Carbon\CarbonImmutable::parse($date, 'Asia/Jakarta')->addDay()->toDateString(),
                ]
            );
            $count++;
        }
    }

    $this->info("Generated {$count} commission report row(s) for {$date}.");
})->purpose("Generate daily per-merchant commission report rows from the previous day's successful volume.");

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

Artisan::command('tickets:auto-create-pending', function (
    \App\Services\Gateway\GatewayManager $gateways,
    \App\Services\TransactionIngestionService $ingestion,
    \App\Services\SupportTicketService $tickets,
) {
    $pendingMinutes = (int) \App\Models\PaygridSetting::value('ticket_pending_minutes', '40');
    $submittedBefore = now()->subMinutes($pendingMinutes);

    $created = 0;
    $checked = 0;

    \App\Models\TopupRequest::query()
        ->where('status', 'pending')
        ->where('submitted_at', '<=', $submittedBefore)
        ->whereDoesntHave('ticket')
        ->with('merchant')
        ->chunkById(200, function ($requests) use ($gateways, $ingestion, $tickets, $pendingMinutes, &$created, &$checked) {
            foreach ($requests as $topup) {
                $merchant = $topup->merchant;
                if (! $merchant) {
                    continue;
                }

                if (! $tickets->canCreateTicket($topup)) {
                    continue;
                }

                $checked++;

                if ($topup->gateway_ref_id) {
                    try {
                        $response = $gateways->for($merchant)->getTransaction($merchant, $topup->gateway_ref_id);
                        $payload = $response['data'] ?? $response;
                        if (is_array($payload)) {
                            $ingestion->ingestForMerchant($merchant, $payload, $merchant->gateway, $merchant->gateway.'_status_pull');
                        }
                    } catch (\Throwable) {
                        // Live re-check failed (e.g. gateway timeout) - fall back to the local
                        // status we already have rather than silently skipping the ticket.
                    }
                    $topup->refresh();
                }

                if ($topup->status !== 'pending') {
                    continue;
                }

                $tickets->createFromTopup($topup, 'Ticket dibuat otomatis sistem setelah pending lebih dari '.$pendingMinutes.' menit (sudah dicek ulang ke gateway).');
                $created++;
            }
        });

    $this->info("Checked {$checked} overdue pending transaction(s), auto-created {$created} ticket(s).");
})->purpose('Re-verify overdue pending transactions against the gateway and auto-create tickets for ones still pending.');

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
