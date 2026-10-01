<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantAdminHistorySortTest extends TestCase
{
    use RefreshDatabase;

    private function makeTopup(Merchant $merchant, string $status, \DateTimeInterface $submittedAt, int $amount = 10000): TopupRequest
    {
        return TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'customer_reference' => 'PLAYER-'.Str::random(6),
            'idempotency_key' => (string) Str::uuid(),
            'public_token' => (string) Str::uuid(),
            'gateway' => $merchant->gateway,
            'data_source' => 'hilogate_pull',
            'gateway_ref_id' => 'qris_'.Str::random(10),
            'status' => $status,
            'amount' => $amount,
            'net_amount' => $amount,
            'fee_amount' => 0,
            'submitted_at' => $submittedAt,
            'succeeded_at' => $status === 'success' ? $submittedAt : null,
        ]);
    }

    public function test_history_page_shows_a_brand_new_pending_transaction_before_old_success_ones(): void
    {
        $merchant = Merchant::query()->create([
            'slug' => 'history-sort-test',
            'name' => 'History Sort Test',
            'merchant_type' => 'script',
            'gateway' => 'hilogate',
            'merchant_id' => 'store-history-sort-test',
            'approval_status' => 'approved',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'admin-history-sort@paygrid.local', 'role' => 'admin',
            'merchant_id' => $merchant->id, 'password' => 'secret',
        ]);

        // Many old, already-settled successes...
        for ($i = 0; $i < 5; $i++) {
            $this->makeTopup($merchant, 'success', now()->subDays(30 + $i));
        }
        // ...and one brand-new pending transaction that just came in.
        $freshPending = $this->makeTopup($merchant, 'pending', now()->subMinute(), 27000);

        $response = $this->actingAs($admin)->get('/portal/history-sort-test/admin/history')->assertOk();

        $html = $response->getContent();
        $pendingPosition = strpos($html, $freshPending->customer_reference);
        $this->assertNotFalse($pendingPosition, 'The fresh pending transaction should appear on the first page of history.');

        $oldestSuccess = TopupRequest::where('status', 'success')->oldest('submitted_at')->first();
        $oldSuccessPosition = strpos($html, $oldestSuccess->customer_reference);
        $this->assertNotFalse($oldSuccessPosition);

        $this->assertLessThan(
            $oldSuccessPosition,
            $pendingPosition,
            'History should be plain chronological (newest first) - the brand-new pending row must render before old success rows, not be sorted behind them.'
        );
    }
}
