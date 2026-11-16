<?php

namespace Tests\Feature;

use App\Http\Controllers\MaController;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantWithdrawal;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaInsentifPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): Merchant
    {
        $agent = Agent::query()->create([
            'name' => 'Insentif Test Agent', 'code' => 'AG-INSENTIF-TEST', 'connection_type' => 'cm', 'is_active' => true,
        ]);

        return Merchant::query()->create([
            'slug' => 'insentif-test-store', 'name' => 'Insentif Test Store', 'agent_id' => $agent->id,
            'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved',
            'merchant_mdr_percent' => 1.10, 'agent_fee_percent' => 0.95, 'ma_fee_percent' => 0.85,
            'cashback_agent_percent' => 0.05, 'marketing_fee_fixed' => 500, 'disbursement_fee_fixed' => 1900,
        ]);
    }

    public function test_insentif_extras_computes_cashback_marketing_and_disbursement(): void
    {
        $this->seed();
        $store = $this->makeStore();

        // 2 successful collection trx totalling 2,000,000 volume.
        foreach (['a', 'b'] as $suffix) {
            TopupRequest::query()->create([
                'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
                'gateway_ref_id' => 'insentif-'.$suffix, 'transaction_id' => 'insentif-'.$suffix,
                'status' => 'success', 'amount' => 1000000, 'net_amount' => 989000, 'fee_amount' => 11000,
                'customer_reference' => 'REF-INSENTIF-'.strtoupper($suffix), 'submitted_at' => now(),
            ]);
        }
        // 1 pending collection trx that must NOT count toward volume/trx_count.
        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'insentif-pending', 'transaction_id' => 'insentif-pending',
            'status' => 'pending', 'amount' => 500000, 'net_amount' => 0, 'fee_amount' => 0,
            'customer_reference' => 'REF-INSENTIF-PENDING', 'submitted_at' => now(),
        ]);

        // 3 completed disbursements that must count toward the disbursement trx count.
        for ($i = 1; $i <= 3; $i++) {
            MerchantWithdrawal::query()->create([
                'merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'insentif-wd-'.$i,
                'status' => 'COMPLETED', 'amount' => 100000, 'bank_name' => 'BCA', 'gateway_created_at' => now(),
            ]);
        }
        // 1 pending disbursement that must NOT count.
        MerchantWithdrawal::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'gateway_withdrawal_id' => 'insentif-wd-pending',
            'status' => 'PENDING', 'amount' => 100000, 'bank_name' => 'BCA', 'gateway_created_at' => now(),
        ]);

        $controller = app(MaController::class);
        $filtersRef = new \ReflectionMethod($controller, 'blankFilters');
        $filters = array_merge($filtersRef->invoke($controller), [
            'store_ids' => [$store->id],
            'from' => now('Asia/Jakarta')->startOfMonth()->toDateString(),
            'to' => now('Asia/Jakarta')->endOfMonth()->toDateString(),
        ]);

        $extrasRef = new \ReflectionMethod($controller, 'insentifExtrasByMerchant');
        $extras = $extrasRef->invoke($controller, [$store->id], $filters)->get($store->id);

        // Cashback = volume (2,000,000) x 0.05% = 1,000.
        $this->assertSame(1000.0, round($extras->cashback, 2));
        // Marketing = 2 successful collection trx x 500 = 1,000.
        $this->assertSame(1000, $extras->marketing);
        // Disbursement = 3 completed withdrawals x 1,900 = 5,700.
        $this->assertSame(5700, $extras->disbursement);
    }

    public function test_insentif_pdf_route_returns_a_pdf_for_the_selected_store(): void
    {
        $this->seed();
        $store = $this->makeStore();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $store->agent->update(['ma_user_id' => $ma->id]);

        TopupRequest::query()->create([
            'merchant_id' => $store->id, 'gateway' => 'hilogate', 'data_source' => 'gateway_pull',
            'gateway_ref_id' => 'insentif-pdf-a', 'transaction_id' => 'insentif-pdf-a',
            'status' => 'success', 'amount' => 1000000, 'net_amount' => 989000, 'fee_amount' => 11000,
            'customer_reference' => 'REF-INSENTIF-PDF-A', 'submitted_at' => now(),
        ]);

        $response = $this->actingAs($ma)->post(route('ma.insentif.pdf'), [
            'store_ids' => [$store->id],
            'month' => now('Asia/Jakarta')->format('Y-m'),
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
