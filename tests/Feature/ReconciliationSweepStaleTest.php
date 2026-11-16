<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\ReconciliationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationSweepStaleTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $slug): Merchant
    {
        $agent = Agent::query()->create(['name' => 'Sweep Agent '.$slug, 'code' => 'AG-SWEEP-'.strtoupper($slug), 'connection_type' => 'cm', 'is_active' => true]);

        return Merchant::query()->create([
            'slug' => $slug, 'name' => $slug, 'agent_id' => $agent->id,
            'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved',
            'merchant_id' => $slug, 'merchant_key' => 'secret',
        ]);
    }

    public function test_marks_a_long_stuck_running_run_as_failed_with_a_reason(): void
    {
        $merchant = $this->merchant('sweep-stale-orphan');
        $run = ReconciliationRun::query()->create([
            'merchant_id' => $merchant->id, 'type' => 'collection', 'month' => '2026-10',
            'status' => 'running', 'started_at' => now()->subHours(2),
        ]);
        $run->timestamps = false;
        $run->forceFill(['updated_at' => now()->subHours(2)])->save();

        $this->artisan('reconciliation:sweep-stale')->assertExitCode(0);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Orphaned', $run->last_error);
    }

    public function test_leaves_a_recently_updated_running_run_alone(): void
    {
        $merchant = $this->merchant('sweep-stale-fresh');
        $run = ReconciliationRun::query()->create([
            'merchant_id' => $merchant->id, 'type' => 'collection', 'month' => '2026-10',
            'status' => 'running', 'started_at' => now(),
        ]);

        $this->artisan('reconciliation:sweep-stale')->assertExitCode(0);

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertNull($run->last_error);
    }

    public function test_leaves_completed_and_failed_runs_alone(): void
    {
        $merchant = $this->merchant('sweep-stale-done');
        $completed = ReconciliationRun::query()->create([
            'merchant_id' => $merchant->id, 'type' => 'collection', 'month' => '2026-09',
            'status' => 'completed', 'started_at' => now()->subHours(3), 'completed_at' => now()->subHours(3),
        ]);
        $completed->timestamps = false;
        $completed->forceFill(['updated_at' => now()->subHours(3)])->save();

        $this->artisan('reconciliation:sweep-stale')->assertExitCode(0);

        $completed->refresh();
        $this->assertSame('completed', $completed->status);
    }
}
