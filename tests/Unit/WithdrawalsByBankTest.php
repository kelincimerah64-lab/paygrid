<?php

namespace Tests\Unit;

use App\Http\Controllers\MaController;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalsByBankTest extends TestCase
{
    use RefreshDatabase;

    private function invoke(string $status, array $merchantIds)
    {
        $controller = app(MaController::class);
        $ref = new \ReflectionMethod($controller, 'withdrawalsByBank');

        return $ref->invoke($controller, collect($merchantIds), $status);
    }

    private function makeMerchant(string $slug): Merchant
    {
        return Merchant::query()->create([
            'slug' => $slug,
            'name' => $slug,
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'approval_status' => 'approved',
        ]);
    }

    public function test_completed_withdrawals_are_grouped_by_bank_and_scoped_to_today(): void
    {
        $merchant = $this->makeMerchant('wd-bank-test');

        MerchantWithdrawal::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'a', 'status' => 'COMPLETED', 'amount' => 100000, 'bank_name' => 'BCA', 'gateway_created_at' => now()]);
        MerchantWithdrawal::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'b', 'status' => 'COMPLETED', 'amount' => 50000, 'bank_name' => 'BCA', 'gateway_created_at' => now()]);
        MerchantWithdrawal::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'c', 'status' => 'COMPLETED', 'amount' => 200000, 'bank_name' => 'MANDIRI', 'gateway_created_at' => now()]);
        // Yesterday's completed withdrawal must NOT count toward "today".
        MerchantWithdrawal::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'd', 'status' => 'COMPLETED', 'amount' => 999999, 'bank_name' => 'BCA', 'gateway_created_at' => now()->subDay()]);

        $result = $this->invoke('COMPLETED', [$merchant->id]);

        $bca = $result->firstWhere('bank_name', 'BCA');
        $mandiri = $result->firstWhere('bank_name', 'MANDIRI');

        $this->assertSame(150000, (int) $bca->volume);
        $this->assertSame(2, (int) $bca->trx_total);
        $this->assertSame(200000, (int) $mandiri->volume);
    }

    public function test_pending_withdrawals_are_not_date_scoped(): void
    {
        $merchant = $this->makeMerchant('wd-bank-pending');

        MerchantWithdrawal::query()->create(['merchant_id' => $merchant->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'old-pending', 'status' => 'PENDING', 'amount' => 75000, 'bank_name' => 'SEABANK', 'gateway_created_at' => now()->subDays(10)]);

        $result = $this->invoke('PENDING', [$merchant->id]);

        $this->assertSame(75000, (int) $result->firstWhere('bank_name', 'SEABANK')->volume);
    }

    public function test_other_merchants_withdrawals_are_excluded(): void
    {
        $merchantA = $this->makeMerchant('wd-bank-scope-a');
        $merchantB = $this->makeMerchant('wd-bank-scope-b');

        MerchantWithdrawal::query()->create(['merchant_id' => $merchantA->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'a1', 'status' => 'COMPLETED', 'amount' => 10000, 'bank_name' => 'BRI', 'gateway_created_at' => now()]);
        MerchantWithdrawal::query()->create(['merchant_id' => $merchantB->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'b1', 'status' => 'COMPLETED', 'amount' => 999999, 'bank_name' => 'BRI', 'gateway_created_at' => now()]);

        $result = $this->invoke('COMPLETED', [$merchantA->id]);

        $this->assertSame(10000, (int) $result->firstWhere('bank_name', 'BRI')->volume);
    }
}
