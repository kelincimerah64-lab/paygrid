<?php

namespace App\Console\Commands;

use App\Models\TopupRequest;
use App\Services\FeeService;
use Illuminate\Console\Command;

class BackfillFeeSnapshotsCommand extends Command
{
    protected $signature = 'paygrid:backfill-fee-snapshots
        {--commit : Persist changes. Omit for dry-run preview}';

    protected $description = "Create fee_snapshots (using each merchant's current MA/agent/toko rates) for topup requests that predate the fee_snapshots feature.";

    public function handle(FeeService $feeService): int
    {
        $commit = (bool) $this->option('commit');

        $total = TopupRequest::query()->whereDoesntHave('feeSnapshot')->count();
        $this->info(($commit ? 'Commit' : 'Dry-run').": {$total} topup request(s) without a fee snapshot.");

        if ($total === 0) {
            return self::SUCCESS;
        }

        if (! $commit) {
            $this->line('Re-run with --commit to write fee_snapshots using each merchant\'s current rates.');

            return self::SUCCESS;
        }

        $created = 0;
        TopupRequest::query()
            ->whereDoesntHave('feeSnapshot')
            ->with('merchant')
            ->chunkById(500, function ($requests) use ($feeService, &$created): void {
                foreach ($requests as $request) {
                    if (! $request->merchant) {
                        continue;
                    }
                    $feeService->snapshot($request->merchant, $request);
                    $created++;
                }
            });

        $this->info("Created {$created} fee snapshot(s).");

        return self::SUCCESS;
    }
}
