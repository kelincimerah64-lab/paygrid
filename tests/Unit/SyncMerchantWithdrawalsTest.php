<?php

namespace Tests\Unit;

use App\Jobs\SyncMerchantWithdrawals;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Services\Gateway\HilogateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncMerchantWithdrawalsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWithdrawalsResponse(array $rows): void
    {
        Http::fake([
            '*/api/v1/withdrawals*' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => $rows,
                'pagination' => ['page' => 1, 'page_size' => 100, 'total_data' => count($rows)],
            ], 200),
        ]);
    }

    private function sampleRow(string $id, int $amount = 1000000): array
    {
        return [
            'id' => $id,
            'ref_id' => 'ref-'.$id,
            'merchant_id' => 'hg-merchant-1',
            'status' => 'COMPLETED',
            'amount' => $amount,
            'net_amount' => $amount + 3000,
            'fee' => 0,
            'total_fee' => 3000,
            'bank_code' => '014',
            'bank_name' => 'BCA',
            'account_number' => '123456',
            'account_name' => 'TEST ACCOUNT',
            'created_at' => now()->timestamp * 1000,
            'completed_at' => now()->timestamp * 1000,
        ];
    }

    public function test_it_upserts_withdrawals_and_never_duplicates_on_a_second_run(): void
    {
        $merchant = Merchant::query()->create([
            'slug' => 'withdrawal-test',
            'name' => 'Withdrawal Test',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'merchant_id' => 'hg-merchant-1',
            'merchant_key' => 'secret',
            'approval_status' => 'approved',
        ]);

        $this->fakeWithdrawalsResponse([$this->sampleRow('wd-1'), $this->sampleRow('wd-2', 2000000)]);

        (new SyncMerchantWithdrawals($merchant->id))->handle(app(HilogateClient::class));

        $this->assertSame(2, MerchantWithdrawal::query()->count());
        $this->assertDatabaseHas('merchant_withdrawals', [
            'gateway_withdrawal_id' => 'wd-1',
            'merchant_id' => $merchant->id,
            'status' => 'COMPLETED',
            'amount' => 1000000,
        ]);

        // Second run with the exact same gateway response must not create duplicates.
        (new SyncMerchantWithdrawals($merchant->id))->handle(app(HilogateClient::class));

        $this->assertSame(2, MerchantWithdrawal::query()->count());
    }

    public function test_a_non_hilogate_merchant_is_skipped_without_error(): void
    {
        $merchant = Merchant::query()->create([
            'slug' => 'other-gateway',
            'name' => 'Other Gateway',
            'merchant_type' => 'cm',
            'gateway' => 'kingspay',
            'merchant_id' => 'kp-1',
            'merchant_key' => 'secret',
            'approval_status' => 'approved',
        ]);

        (new SyncMerchantWithdrawals($merchant->id))->handle(app(HilogateClient::class));

        $this->assertSame(0, MerchantWithdrawal::query()->count());
    }
}
