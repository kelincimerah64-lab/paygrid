<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaApprovalExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_counts_reflect_pending_approved_and_rejected_registrations(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->where('ma_user_id', $ma->id)->firstOrFail();

        MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => Str::random(20),
            'store_name' => 'Pending Store',
            'status' => 'pending_ma',
            'payload' => ['admin_email' => 'pending@paygrid-test.local'],
        ]);
        MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => Str::random(20),
            'store_name' => 'Approved Store',
            'status' => 'approved',
            'payload' => ['admin_email' => 'approved@paygrid-test.local'],
        ]);
        MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => Str::random(20),
            'store_name' => 'Rejected Store',
            'status' => 'rejected',
            'payload' => ['admin_email' => 'rejected@paygrid-test.local'],
        ]);

        $page = $this->actingAs($ma)->get(route('ma.approvals'))->assertOk();
        $page->assertSeeText('Pending');
        $page->assertSeeText('Approved');
        $page->assertSeeText('Rejected');
    }

    public function test_sidebar_badge_clears_after_visiting_the_approval_page(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->where('ma_user_id', $ma->id)->firstOrFail();

        MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => Str::random(20),
            'store_name' => 'Badge Store',
            'status' => 'pending_ma',
            'payload' => ['admin_email' => 'badge@paygrid-test.local'],
            'submitted_to_ma_at' => now(),
        ]);

        $beforeHtml = $this->actingAs($ma)->get(route('ma.overview'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Request Approval.{0,80}?nav-badge">1</s', $beforeHtml);

        $this->actingAs($ma)->get(route('ma.approvals'))->assertOk();

        $afterHtml = $this->actingAs($ma)->get(route('ma.overview'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/Request Approval.{0,80}?nav-badge">1</s', $afterHtml);
    }

    public function test_export_is_rejected_for_non_approved_registration(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->where('ma_user_id', $ma->id)->firstOrFail();

        $registration = MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => Str::random(20),
            'store_name' => 'Not Approved Store',
            'status' => 'pending_ma',
            'payload' => ['admin_email' => 'notapproved@paygrid-test.local'],
        ]);

        $this->actingAs($ma)
            ->get(route('ma.merchant-registration.export', $registration))
            ->assertStatus(422);
    }

    public function test_export_returns_excel_file_for_approved_registration(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $agent = Agent::query()->where('ma_user_id', $ma->id)->firstOrFail();

        $merchant = Merchant::query()->create([
            'agent_id' => $agent->id,
            'name' => 'Exportable Store',
            'slug' => 'exportable-store',
            'merchant_id' => 'MERCH-EXPORT-1',
            'merchant_key' => 'secret-key-123',
            'gateway' => 'hilogate',
            'merchant_type' => 'cm',
            'approval_status' => 'approved',
            'merchant_mdr_percent' => 1.1,
            'agent_fee_percent' => 0.95,
            'ma_fee_percent' => 0.85,
        ]);

        $registration = MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => Str::random(20),
            'merchant_id' => $merchant->id,
            'store_name' => 'Exportable Store',
            'status' => 'approved',
            'payload' => ['admin_email' => 'export@paygrid-test.local'],
        ]);

        $response = $this->actingAs($ma)->get(route('ma.merchant-registration.export', $registration));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.ms-excel');
        $this->assertStringContainsString('attachment; filename="export-toko-exportable-store', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('MERCH-EXPORT-1', $response->getContent());
        $this->assertStringContainsString('<Workbook', $response->getContent());
    }
}
