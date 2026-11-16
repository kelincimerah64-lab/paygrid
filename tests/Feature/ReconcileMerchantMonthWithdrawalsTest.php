<?php

namespace Tests\Feature;

use App\Jobs\ReconcileMerchantMonthWithdrawals;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileMerchantMonthWithdrawalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_walks_past_an_already_known_page_to_find_a_gap_behind_it(): void
    {
        config()->set('paygrid.gateway.hilogate.base_url', 'https://app.hilogate.test/api');
        config()->set('paygrid.gateway.hilogate.merchant_id', 'platform-merchant');
        config()->set('paygrid.gateway.hilogate.secret_key', 'platform-secret');

        $agent = Agent::query()->create(['name' => 'Reconcile WD Agent', 'code' => 'AG-RECONCILE-WD', 'connection_type' => 'cm', 'is_active' => true]);
        $merchant = Merchant::query()->create([
            'slug' => 'reconcile-wd-store', 'name' => 'Reconcile WD Store', 'agent_id' => $agent->id,
            'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved',
            'merchant_id' => 'store-reconcile-wd', 'merchant_key' => 'store-secret',
        ]);

        // Already known locally - the normal SyncMerchantWithdrawals job would
        // stop paging right after this page, since every row on it is known.
        MerchantWithdrawal::query()->create([
            'merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'wd-known-1',
            'status' => 'COMPLETED', 'amount' => 2000000, 'net_amount' => 1997000, 'fee' => 3000,
        ]);

        $knownAt = CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta')->getTimestampMs();
        $gapAt = CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Jakarta')->getTimestampMs();

        Http::fake([
            'https://app.hilogate.test/api/v1/withdrawals*' => Http::sequence()
                ->push(['data' => [['id' => 'wd-known-1', 'status' => 'COMPLETED', 'amount' => 2000000, 'net_amount' => 1997000, 'total_fee' => 3000, 'created_at' => $knownAt]]])
                ->push(['data' => [['id' => 'wd-gap', 'status' => 'COMPLETED', 'amount' => 750000, 'net_amount' => 747000, 'total_fee' => 3000, 'created_at' => $gapAt]]])
                ->push(['data' => []]),
        ]);

        ReconcileMerchantMonthWithdrawals::dispatchSync($merchant->id, '2026-10');

        $this->assertDatabaseHas('merchant_withdrawals', ['gateway_withdrawal_id' => 'wd-gap', 'amount' => 750000]);
        $this->assertSame(2, MerchantWithdrawal::query()->where('merchant_id', $merchant->id)->count());

        $run = ReconciliationRun::query()->where('merchant_id', $merchant->id)->where('type', 'withdrawal')->where('month', '2026-10')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->records_added);
        $this->assertSame(1, $run->records_updated);
    }
}
