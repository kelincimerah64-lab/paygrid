<?php

namespace Tests\Feature;

use App\Models\GatewaySyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenancePruneCommandTest extends TestCase
{
    use RefreshDatabase;

    private function logAt(string $direction, string $status, \Carbon\CarbonInterface $createdAt): GatewaySyncLog
    {
        $log = GatewaySyncLog::create(['gateway' => 'hilogate', 'direction' => $direction, 'status' => $status]);
        $log->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $log;
    }

    public function test_it_prunes_old_success_logs_regardless_of_direction(): void
    {
        $oldPull = $this->logAt('pull', 'success', now()->subHours(10));
        $oldBackfill = $this->logAt('backfill', 'success', now()->subHours(10));
        $oldCallback = $this->logAt('callback', 'success', now()->subHours(10));
        $recentBackfill = $this->logAt('backfill', 'success', now()->subMinutes(5));

        $this->artisan('paygrid:maintenance-prune')->assertSuccessful();

        $this->assertDatabaseMissing('gateway_sync_logs', ['id' => $oldPull->id]);
        $this->assertDatabaseMissing('gateway_sync_logs', ['id' => $oldBackfill->id]);
        $this->assertDatabaseMissing('gateway_sync_logs', ['id' => $oldCallback->id]);
        $this->assertDatabaseHas('gateway_sync_logs', ['id' => $recentBackfill->id]);
    }

    public function test_it_keeps_failed_logs_within_the_retention_window_regardless_of_direction(): void
    {
        $withinWindow = $this->logAt('backfill', 'failed', now()->subDays(10));
        $beyondWindow = $this->logAt('backfill', 'failed', now()->subDays(20));

        $this->artisan('paygrid:maintenance-prune')->assertSuccessful();

        $this->assertDatabaseHas('gateway_sync_logs', ['id' => $withinWindow->id]);
        $this->assertDatabaseMissing('gateway_sync_logs', ['id' => $beyondWindow->id]);
    }
}
