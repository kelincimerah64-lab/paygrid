<?php

namespace Tests\Feature;

use App\Models\FeeSnapshot;
use App\Models\Merchant;
use App\Models\TopupRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillFeeSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createTopupWithoutSnapshot(Merchant $merchant): TopupRequest
    {
        return TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'customer_reference' => 'PLAYER-BACKFILL',
            'idempotency_key' => 'backfill-'.Str::random(8),
            'public_token' => (string) Str::uuid(),
            'gateway' => $merchant->gateway,
            'data_source' => 'gateway_create',
            'payment_id' => 'qris_backfill_test',
            'gateway_ref_id' => 'backfill-ref',
            'status' => 'success',
            'amount' => 1000000,
            'net_amount' => 989000,
            'fee_amount' => 11000,
            'submitted_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function test_dry_run_reports_missing_snapshots_without_writing_any(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $topup = $this->createTopupWithoutSnapshot($merchant);

        $this->artisan('paygrid:backfill-fee-snapshots')->assertSuccessful();

        $this->assertDatabaseMissing('fee_snapshots', ['topup_request_id' => $topup->id]);
    }

    public function test_commit_creates_a_snapshot_using_the_merchants_current_rates(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $topup = $this->createTopupWithoutSnapshot($merchant);

        $this->artisan('paygrid:backfill-fee-snapshots', ['--commit' => true])->assertSuccessful();

        $snapshot = FeeSnapshot::query()->where('topup_request_id', $topup->id)->firstOrFail();
        $this->assertSame((float) $merchant->merchant_mdr_percent, (float) $snapshot->merchant_mdr_percent);
        $this->assertSame((float) $merchant->agent_fee_percent, (float) $snapshot->agent_fee_percent);
        $this->assertSame((float) $merchant->ma_fee_percent, (float) $snapshot->ma_fee_percent);
    }

    public function test_commit_does_not_touch_topups_that_already_have_a_snapshot(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $topup = $this->createTopupWithoutSnapshot($merchant);
        $existing = FeeSnapshot::query()->create([
            'topup_request_id' => $topup->id,
            'merchant_id' => $merchant->id,
            'merchant_mdr_percent' => 9.99,
            'ma_fee_percent' => 9.99,
            'agent_fee_percent' => 9.99,
        ]);

        $this->artisan('paygrid:backfill-fee-snapshots', ['--commit' => true])->assertSuccessful();

        $this->assertSame(9.99, (float) $existing->fresh()->merchant_mdr_percent);
    }
}
