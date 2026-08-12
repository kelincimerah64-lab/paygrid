<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantGatewayBalance;
use App\Models\MerchantRegistration;
use App\Models\Agent;
use App\Models\AgentOnboardingLink;
use App\Models\SupportTicket;
use App\Models\TopupRequest;
use App\Models\User;
use App\Services\TransactionIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PayGridRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cm_merchant_gets_topup_and_success_checklist_menu(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail());

        $this->get('/portal/nnp-cm-bj/cs/tickets')
            ->assertOk()
            ->assertSee('Topup Request')
            ->assertSee('Sukses Checklist');
    }

    public function test_security_headers_are_applied(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_script_merchant_only_gets_history_menu(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'michael@paygrid.local')->firstOrFail());

        $this->get('/portal/valohoki-1lg/cs/tickets')
            ->assertOk()
            ->assertSee('History TRX')
            ->assertDontSee('Topup Request')
            ->assertDontSee('>Sukses Checklist</a>', false);
    }

    public function test_script_cs_can_open_history_but_not_cm_routes(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'valohoki-1lg')->firstOrFail();
        $user = User::query()->create([
            'name' => 'CS Script',
            'email' => 'cs-script@paygrid.local',
            'role' => 'cs',
            'merchant_id' => $merchant->id,
            'password' => Hash::make('Rahasia123'),
        ]);

        $this->actingAs($user)
            ->get('/portal/valohoki-1lg/cs/history')
            ->assertOk()
            ->assertSee('History Transaksi')
            ->assertSee('Tindak Lanjut')
            ->assertDontSee('>Topup Request</a>', false)
            ->assertDontSee('>Sukses Checklist</a>', false);

        $this->get('/portal/valohoki-1lg/cs/topup')->assertNotFound();
        $this->get('/portal/valohoki-1lg/cs/checklist')->assertNotFound();
        $this->get('/portal/nnp-cm-bj/cs/history')->assertForbidden();
    }

    public function test_script_cs_login_ignores_stale_intended_url(): void
    {
        $this->seed();

        $this->withSession(['url.intended' => '/portal/nnp-cm-bj/cs/tickets'])
            ->post('/login', [
                'email' => 'tes@mail.com',
                'password' => 'tes123',
            ])
            ->assertRedirect('/portal/tiktok5000/cs/tickets');
    }

    public function test_script_merchant_cannot_open_cm_only_route(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'michael@paygrid.local')->firstOrFail());

        $this->get('/portal/valohoki-1lg/cs/checklist')->assertNotFound();
        $this->get('/portal/valohoki-1lg/cs/topup')->assertNotFound();
    }

    public function test_topup_status_uses_local_qr_generation(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $topup = TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'customer_reference' => 'PLAYER-QR-LOCAL',
            'idempotency_key' => 'qr-local-test',
            'gateway' => $merchant->gateway,
            'data_source' => 'gateway_create',
            'payment_id' => 'qris_local_test',
            'gateway_ref_id' => 'qr-local-ref',
            'qr_string' => '00020101021226670016COM.NOBUBANK.WWW01189360050300000879140214521728082908240303UMI51440014ID.CO.QRIS.WWW0215ID10253885026600303UMI5204581253033605405100005802ID5907PAYGRID6007JAKARTA6105123456304A13A',
            'payment_url' => 'https://api.qrserver.com/should-not-render.png',
            'status' => 'pending',
            'amount' => 10000,
            'net_amount' => 9890,
            'fee_amount' => 110,
            'submitted_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->get(route('topup.status', [$merchant, $topup]))
            ->assertOk()
            ->assertSee(route('topup.qr', [$merchant, $topup]), false)
            ->assertDontSee('api.qrserver.com', false);

        $this->get(route('topup.qr', [$merchant, $topup]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertSee('<svg', false);
    }

    public function test_merchant_cs_cannot_access_other_merchant_portal(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail());

        $this->get('/portal/bl77/cs/tickets')->assertForbidden();
    }

    public function test_merchant_cs_cannot_access_finance_routes(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail());

        $this->get('/portal/nnp-cm-bj/finance/overview')->assertForbidden();
    }

    public function test_finance_users_are_scoped_for_cm_and_script_merchants(): void
    {
        $this->seed();

        $this->actingAs(User::query()->where('email', 'finance@nnp-cm-bj.local')->firstOrFail())
            ->get('/portal/nnp-cm-bj/finance/overview')
            ->assertOk()
            ->assertSee('Finance Overview')
            ->assertSee('Available Balance')
            ->assertSee('Total Transaksi')
            ->assertSee('Total Volume')
            ->assertSee('BJ0001');

        $this->get('/portal/bl77/finance/overview')->assertForbidden();
        $this->get('/portal/nnp-cm-bj/cs/tickets')->assertForbidden();

        $this->get('/portal/nnp-cm-bj/finance/settlement')
            ->assertOk()
            ->assertSee('Coming soon')
            ->assertDontSee('Data Transaksi Bulanan');
        $this->get('/portal/nnp-cm-bj/finance/request-settlement')->assertNotFound();

        $this->actingAs(User::query()->where('email', 'finance@tiktok5000.local')->firstOrFail())
            ->get('/portal/tiktok5000/finance/report')
            ->assertOk()
            ->assertSee('SCRIPT Finance')
            ->assertSee('Report Finance')
            ->assertSee('Report Transaksi Toko')
            ->assertSee('Report Settlement Toko')
            ->assertDontSee('Topup Request');
    }

    public function test_superadmin_can_manage_fee_structure_timer_and_accounts(): void
    {
        $this->seed();
        $superadmin = User::query()->where('email', 'superadmin@paygrid.local')->firstOrFail();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $cs = User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail();

        $this->post('/login', [
            'email' => 'superadmin@paygrid.local',
            'password' => 'Rahasia123',
        ])->assertRedirect('/superadmin');

        $this->actingAs($superadmin)
            ->get('/superadmin')
            ->assertOk()
            ->assertSee('Dashboard Fee')
            ->assertSee('Add Fee')
            ->assertSee('Merchant Group')
            ->assertSee('Timer Ticket')
            ->assertSee('Daftar Account');

        $this->post(route('superadmin.ma.store'), [
            'name' => 'MA Baru',
            'email' => 'ma-baru@paygrid.local',
            'contact' => '0812',
            'is_active' => 1,
            'password' => 'Rahasia123',
            'base_hg_percent' => '0,80',
            'connection_type' => 'cm',
            'connection_fee_percent' => '0,05',
            'settlement_method' => 'h_plus_1',
            'settlement_fee_percent' => '0,05',
            'ma_fee_percent' => '0,15',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['email' => 'ma-baru@paygrid.local', 'role' => 'ma']);

        $this->post(route('superadmin.merchant-fee.update', $merchant), [
            'base_mdr_percent' => '0.80',
            'connection_fee_percent' => '0.05',
            'settlement_method' => 'same_day',
            'settlement_fee_percent' => '0.10',
            'ma_fee_percent' => '0.15',
            'agent_fee_percent' => '0.05',
            'toko_fee_percent' => '0.20',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('1.3500', (string) $merchant->refresh()->merchant_mdr_percent);

        $this->post(route('superadmin.agent.store'), [
            'ma_user_id' => User::query()->where('email', 'ma-baru@paygrid.local')->firstOrFail()->id,
            'name' => 'Group Baru',
            'email' => 'group@paygrid.local',
            'contact' => '0813',
            'base_hg_percent' => '0.80',
            'connection_fee_percent' => '0.05',
            'settlement_method' => 'everyday',
            'settlement_fee_percent' => '0.05',
            'ma_fee_percent' => '0.15',
            'default_agent_fee_percent' => '0.05',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('agents', ['code' => 'AG-GROUP-BARU', 'name' => 'Group Baru']);
        $this->assertDatabaseHas('users', ['username' => 'AG-GROUP-BARU', 'role' => 'agent']);

        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', [
            'email' => 'AG-GROUP-BARU',
            'password' => 'Rahasia123',
        ])->assertRedirect('/agent');
        $this->actingAs($superadmin);

        $this->post(route('superadmin.timer-ticket.update'), [
            'ticket_pending_minutes' => 45,
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('paygrid_settings', ['key' => 'ticket_pending_minutes', 'value' => '45']);

        $this->post(route('superadmin.accounts.reset', $cs), [
            'password' => 'Baru12345',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertTrue(Hash::check('Baru12345', $cs->refresh()->password));
    }

    public function test_merchant_admin_can_manage_users_settings_logs_and_is_scoped(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $admin = User::query()->where('email', 'admin@nnp-cm-bj.local')->firstOrFail();

        $this->post('/login', [
            'email' => 'admin@nnp-cm-bj.local',
            'password' => 'Rahasia123',
        ])->assertRedirect('/portal/nnp-cm-bj/admin/users');

        $this->actingAs($admin)
            ->get('/portal/nnp-cm-bj/admin/users')
            ->assertOk()
            ->assertSee('Data User')
            ->assertSee('Rahasia123')
            ->assertSee('>Topup Request</a>', false)
            ->assertSee('>Sukses Checklist</a>', false)
            ->assertDontSee('>History TRX</a>', false);

        $this->get('/portal/nnp-cm-bj/admin/qris')->assertOk()->assertSee('Topup Request');
        $this->get('/portal/nnp-cm-bj/admin/checklist')->assertOk()->assertSee('Sukses Checklist');
        $this->get('/portal/nnp-cm-bj/admin/history')->assertNotFound();

        $this->post(route('merchant.admin.users.store', $merchant), [
            'email' => 'finance-baru@nnp-cm-bj.local',
            'role' => 'finance',
            'password' => 'PassBaru123',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'finance-baru@nnp-cm-bj.local', 'role' => 'finance', 'merchant_id' => $merchant->id]);

        $created = User::query()->where('email', 'finance-baru@nnp-cm-bj.local')->firstOrFail();
        $this->post(route('merchant.admin.users.reset-password', [$merchant, $created]), ['password' => 'Reset123'])
            ->assertRedirect();
        $this->assertTrue(Hash::check('Reset123', $created->refresh()->password));
        $this->assertSame('Reset123', $created->plain_password);

        $this->post(route('merchant.admin.minimum-topup.update', $merchant), ['minimum_topup_amount' => 25000])
            ->assertRedirect();
        $this->assertSame(25000, $merchant->refresh()->minimum_topup_amount);

        $request = TopupRequest::query()->where('merchant_id', $merchant->id)->where('status', 'success')->where('is_processed', false)->firstOrFail();
        $this->patch(route('api.checklist.update', $request), ['checked' => true])->assertRedirect();
        $this->get('/portal/nnp-cm-bj/admin/logs')
            ->assertOk()
            ->assertSee('Ubah status transaksi')
            ->assertSee('Transaksi');

        $this->get('/portal/bl77/admin/users')->assertForbidden();
        $this->get('/ma')->assertForbidden();
    }

    public function test_script_admin_only_gets_history_menu(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@tiktok5000.local')->firstOrFail();

        $this->actingAs($admin)
            ->get('/portal/tiktok5000/admin/users')
            ->assertOk()
            ->assertSee('>History TRX</a>', false)
            ->assertDontSee('>Topup Request</a>', false)
            ->assertDontSee('>Sukses Checklist</a>', false);

        $this->get('/portal/tiktok5000/admin/history')->assertOk()->assertSee('History TRX');
        $this->get('/portal/tiktok5000/admin/qris')->assertNotFound();
        $this->get('/portal/tiktok5000/admin/checklist')->assertNotFound();
    }

    public function test_unscoped_cs_cannot_login(): void
    {
        User::query()->create([
            'name' => 'Unscoped CS',
            'email' => 'unscoped-cs@paygrid.local',
            'role' => 'cs',
            'password' => Hash::make('Rahasia123'),
        ]);

        $this->post('/login', [
            'email' => 'unscoped-cs@paygrid.local',
            'password' => 'Rahasia123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_logout_and_failed_login_are_audited_and_rate_limited(): void
    {
        $this->seed();

        $this->post('/login', [
            'email' => 'cs-bj@paygrid.local',
            'password' => 'salah',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);

        $this->post('/login', [
            'email' => 'cs-bj@paygrid.local',
            'password' => 'Rahasia123',
        ])->assertRedirect('/portal/nnp-cm-bj/cs/tickets');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_success']);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'cs-bj@paygrid.local',
                'password' => 'salah',
            ]);
        }

        $this->post('/login', [
            'email' => 'cs-bj@paygrid.local',
            'password' => 'salah',
        ])->assertSessionHasErrors('email');
    }

    public function test_merchant_cs_cannot_checklist_other_merchant_transaction(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail());

        $request = TopupRequest::query()
            ->whereRelation('merchant', 'slug', 'bl77')
            ->where('status', 'success')
            ->firstOrFail();

        $this->patchJson("/api/topup-requests/{$request->id}/checklist", ['checked' => true])
            ->assertForbidden();
    }

    public function test_checklist_is_persisted_to_database(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail();
        $request = TopupRequest::query()
            ->where('status', 'success')
            ->where('is_processed', false)
            ->firstOrFail();

        $this->actingAs($user)
            ->patchJson("/api/topup-requests/{$request->id}/checklist", ['checked' => true])
            ->assertOk()
            ->assertJsonPath('is_processed', true)
            ->assertJsonPath('checked_by_email', $user->email);

        $this->assertDatabaseHas('topup_requests', [
            'id' => $request->id,
            'is_processed' => true,
            'checked_by_email' => $user->email,
        ]);
    }

    public function test_cs_can_submit_ticket_with_required_image_attachment(): void
    {
        Storage::fake('public');
        $this->seed();

        $user = User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $ticket = SupportTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();

        $this->actingAs($user)
            ->post(route('merchant.cs.ticket.submit', [$merchant, $ticket]), [
                'attachment' => UploadedFile::fake()->image('proof.jpg', 320, 240),
                'note' => 'Bukti dari CS toko.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Tiket berhasil dikirim ke CS pusat.');

        $ticket->refresh();

        $this->assertSame('open', $ticket->status);
        $this->assertNotNull($ticket->submitted_to_center_at);
        $this->assertSame('Bukti dari CS toko.', $ticket->note);
        $this->assertCount(1, $ticket->attachments);
        Storage::disk('public')->assertExists($ticket->attachments[0]['path']);

        $this->actingAs($user)
            ->post(route('merchant.cs.ticket.submit', [$merchant, $ticket]), [
                'attachment' => UploadedFile::fake()->image('proof-2.jpg', 320, 240),
                'note' => 'Submit ulang.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Tiket sudah dikirim ke CS pusat. Tunggu update status dari CS pusat.');
        $this->assertCount(1, $ticket->refresh()->attachments);
    }

    public function test_cs_pusat_can_receive_and_update_submitted_ticket_status(): void
    {
        Storage::fake('public');
        $this->seed();

        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $ticket = SupportTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $cs = User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail();

        $this->actingAs($cs)
            ->post(route('merchant.cs.ticket.submit', [$merchant, $ticket]), [
                'attachment' => UploadedFile::fake()->image('proof.jpg'),
                'note' => 'Bukti toko.',
            ])
            ->assertRedirect();

        $center = User::query()->where('email', 'cs-pusat@paygrid.local')->firstOrFail();
        $this->actingAs($center)
            ->get('/cs-pusat')
            ->assertOk()
            ->assertSee($ticket->ticket_no)
            ->assertSee('Bukti');

        $this->post(route('center-support.tickets.update', $ticket), [
            'center_status' => 'issue_bank',
            'center_note' => 'testing only',
        ])->assertRedirect()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame('issue_bank', $ticket->center_status);
        $this->assertSame('testing only', $ticket->center_note);
        $this->assertSame('in_progress', $ticket->status);

        $this->get('/cs-pusat')
            ->assertOk()
            ->assertSee('Terkirim');

        $this->get('/cs-pusat?delivery=sent')
            ->assertOk()
            ->assertSee($ticket->ticket_no);

        $this->get('/cs-pusat?delivery=pending')
            ->assertOk()
            ->assertDontSee($ticket->ticket_no);

        $this->post(route('center-support.tickets.update', $ticket), [
            'center_status' => 'success',
            'center_note' => 'should not replace',
        ])->assertRedirect()->assertSessionHas('status');

        $ticket->refresh();
        $this->assertSame('issue_bank', $ticket->center_status);
        $this->assertSame('testing only', $ticket->center_note);
        $this->assertSame('in_progress', $ticket->status);

        $this->actingAs($cs)
            ->get('/portal/nnp-cm-bj/cs/tickets')
            ->assertOk()
            ->assertSee('ISSUE BANK')
            ->assertSee('testing only');
    }

    public function test_cs_can_create_ticket_from_expired_topup_without_attachment(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $topup = TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'data_source' => 'gateway_pull',
            'payment_id' => 'qris_ticket_test',
            'gateway_ref_id' => 'expired-ticket-ref',
            'transaction_id' => 'expired-ticket-trx',
            'status' => 'expired',
            'amount' => 100000,
            'net_amount' => 98900,
            'fee_amount' => 1100,
            'is_processed' => false,
            'submitted_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user)
            ->post(route('merchant.cs.topup.ticket', [$merchant, $topup]), [
                'note' => 'User sudah kirim bukti bayar.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Transaksi berhasil jadi tiket. Buka menu Tickets untuk submit ke CS pusat.');

        $ticket = SupportTicket::query()->where('topup_request_id', $topup->id)->firstOrFail();

        $this->assertSame($merchant->id, $ticket->merchant_id);
        $this->assertSame('not_started', $ticket->status);
        $this->assertSame('User sudah kirim bukti bayar.', $ticket->note);
        $this->assertNull($ticket->submitted_to_center_at);
        $this->assertEmpty($ticket->attachments ?? []);

        $this->actingAs($user)
            ->get('/portal/nnp-cm-bj/cs/topup?status=expired&period=all')
            ->assertOk()
            ->assertSee('Sudah Ticket');

        $this->actingAs($user)
            ->post(route('merchant.cs.topup.ticket', [$merchant, $topup]), ['note' => 'Submit kedua'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Transaksi ini sudah menjadi tiket. Buka menu Tickets untuk submit ke CS pusat.');

        $this->assertSame(1, SupportTicket::query()->where('topup_request_id', $topup->id)->count());
    }

    public function test_script_cs_can_create_ticket_from_history_without_attachment(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'valohoki-1lg')->firstOrFail();
        $user = User::query()->create([
            'name' => 'CS Script',
            'email' => 'cs-script-ticket@paygrid.local',
            'role' => 'cs',
            'merchant_id' => $merchant->id,
            'password' => Hash::make('Rahasia123'),
        ]);
        $topup = TopupRequest::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'data_source' => 'gateway_pull',
            'payment_id' => 'script_ticket_test',
            'gateway_ref_id' => 'script-expired-ref',
            'transaction_id' => 'script-expired-trx',
            'status' => 'expired',
            'amount' => 125000,
            'net_amount' => 123000,
            'fee_amount' => 2000,
            'is_processed' => false,
            'submitted_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($user)
            ->get('/portal/valohoki-1lg/cs/history?status=expired&period=all')
            ->assertOk()
            ->assertSee('Ticket');

        $this->actingAs($user)
            ->post(route('merchant.cs.topup.ticket', [$merchant, $topup]), ['note' => 'Ticket script dari history.'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Transaksi berhasil jadi tiket. Buka menu Tickets untuk submit ke CS pusat.');

        $this->assertDatabaseHas('support_tickets', [
            'merchant_id' => $merchant->id,
            'topup_request_id' => $topup->id,
            'status' => 'not_started',
            'note' => 'Ticket script dari history.',
        ]);
    }

    public function test_ma_lists_all_approved_seed_merchants(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'michael@paygrid.local')->firstOrFail());

        $this->get('/ma')
            ->assertOk()
            ->assertSee('nnp CM - BJ')
            ->assertSee('BL77')
            ->assertSee('Valohoki [1LG]');
    }

    public function test_gateway_sync_preserves_existing_checklist_state(): void
    {
        $this->seed();

        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $request = TopupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', 'success')
            ->where('is_processed', true)
            ->firstOrFail();

        app(TransactionIngestionService::class)->ingestForMerchant($merchant, [
            'id' => $request->gateway_ref_id,
            'status' => 'SUCCESS',
            'merchant_id' => $merchant->merchant_id,
            'payment_id_provider' => $request->payment_id,
            'ref_id' => $request->transaction_id,
            'amount' => 50000,
            'net_amount' => 49450,
            'rrn' => 'UPDATEDRRN',
            'created_at' => $request->submitted_at->getTimestampMs(),
        ], $merchant->gateway, 'gateway_pull');

        $this->assertDatabaseHas('topup_requests', [
            'id' => $request->id,
            'is_processed' => true,
            'checked_by_email' => $request->checked_by_email,
            'rrn' => 'UPDATEDRRN',
        ]);
    }

    public function test_dashboard_reads_gateway_balance_from_matching_merchant_cache(): void
    {
        $this->seed();

        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $other = Merchant::query()->where('slug', 'bl77')->firstOrFail();
        MerchantGatewayBalance::query()->create([
            'merchant_id' => $merchant->id,
            'gateway' => $merchant->gateway,
            'active_balance' => 123456,
            'pending_balance' => 7890,
            'synced_at' => now(),
        ]);
        MerchantGatewayBalance::query()->create([
            'merchant_id' => $other->id,
            'gateway' => $other->gateway,
            'active_balance' => 999999,
            'pending_balance' => 999999,
            'synced_at' => now(),
        ]);

        $this->actingAs(User::query()->where('email', 'cs-bj@paygrid.local')->firstOrFail())
            ->get('/portal/nnp-cm-bj/cs/checklist')
            ->assertOk()
            ->assertSee('123.456')
            ->assertDontSee('999.999');
    }

    public function test_gateway_callback_upserts_transaction_and_metrics(): void
    {
        $this->seed();

        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        config()->set('paygrid.gateway.hilogate.secret_key', 'callback-test-secret');

        $payload = [
            'id' => 'callback-ref-001',
            'status' => 'SUCCESS',
            'merchant_id' => $merchant->merchant_id,
            'payment_id_provider' => 'pay-001',
            'ref_id' => 'client-001',
            'amount' => 100000,
            'net_amount' => 98900,
            'rrn' => 'RRN001',
            'created_at' => now('Asia/Jakarta')->getTimestampMs(),
        ];
        $signature = md5('/api/callbacks/hilogate/transaction'.json_encode($payload).'callback-test-secret');

        $this->withHeaders(['X-Signature' => $signature])
            ->postJson('/api/callbacks/hilogate/transaction', $payload)
            ->assertAccepted();

        $this->assertDatabaseHas('topup_requests', [
            'merchant_id' => $merchant->id,
            'gateway_ref_id' => 'callback-ref-001',
            'status' => 'success',
            'amount' => 100000,
        ]);

        $this->assertTrue(
            $merchant->metrics()->whereDate('metric_date', now('Asia/Jakarta')->toDateString())->exists(),
        );
    }

    public function test_ma_pages_and_local_actions_work(): void
    {
        $this->seed();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $agent = Agent::query()->where('code', 'AG-OTHER')->firstOrFail();

        $this->actingAs($ma);

        foreach (['/ma', '/ma/report', '/ma/fee', '/ma/approvals', '/ma/mapping', '/ma/stores', '/ma/agents', '/ma/create-store'] as $path) {
            $this->get($path)->assertOk();
        }

        $this->get('/ma/report/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->post(route('ma.agents.store'), [
            'name' => 'Agen Lokal',
            'email' => 'agen-lokal@paygrid.local',
            'contact' => '0812',
            'status' => 'Active',
            'default_agent_fee_percent' => '0,15',
            'password' => 'Rahasia123',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('agents', ['code' => 'AGN-AGEN-LOKAL', 'name' => 'Agen Lokal']);
        $this->assertDatabaseHas('users', ['username' => 'AGN-AGEN-LOKAL', 'role' => 'agent']);

        $this->post(route('ma.mapping.update', $merchant), ['agent_id' => $agent->id])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame($agent->id, $merchant->refresh()->agent_id);

        $this->post(route('ma.create-store.store'), [
            'name' => 'MA Store Local',
            'username' => 'ma-store-local',
            'engine_name' => 'GENESIS DIGITAL',
            'agent_id' => $agent->id,
            'pic_email' => 'pic-ma-store@paygrid.local',
            'admin_email' => 'admin-ma-store@paygrid.local',
            'environment' => 'Production',
            'gateway' => 'hilogate',
            'merchant_type' => 'cm',
            'transaction_callback_url' => 'http://topup.15.232.137.74.nip.io/api/callbacks/hilogate/transaction',
            'withdrawal_callback_url' => 'http://topup.15.232.137.74.nip.io/api/callbacks/hilogate/withdrawal',
            'api_ip_whitelist' => '15.232.137.74',
            'settlement_method' => 'standard_h1',
            'merchant_mdr_percent' => '1.2',
            'base_mdr_percent' => '0.8',
            'connection_fee_percent' => '0.05',
            'settlement_fee_percent' => '0.05',
            'agent_fee_percent' => '0.15',
            'toko_fee_percent' => '0',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('merchants', ['slug' => 'ma-store-local', 'name' => 'MA Store Local', 'approval_status' => 'approved']);
        $this->assertDatabaseHas('users', ['email' => 'admin-ma-store@paygrid.local', 'role' => 'admin']);
    }

    public function test_ma_can_approve_merchant_registration_and_audit_it(): void
    {
        $this->seed();
        $agent = Agent::query()->where('code', 'AG-EPC')->firstOrFail();
        $registration = MerchantRegistration::query()->create([
            'agent_id' => $agent->id,
            'token' => 'approval-test-token',
            'store_name' => 'Approval Test Store',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'status' => 'pending_ma',
            'payload' => [
                'merchant_id' => 'hg-approval-id',
                'merchant_key' => 'hg-approval-key',
                'merchant_group_id' => 'hg-group-id',
                'transaction_callback_url' => 'https://example.test/transaction',
                'withdrawal_callback_url' => 'https://example.test/withdrawal',
                'pic_email' => 'pic-approval@paygrid.local',
                'finance_email' => 'finance-approval@paygrid.local',
                'cs_email' => 'cs-approval@paygrid.local',
                'settlement_fee_percent' => 0.05,
                'disbursement_fee_fixed' => 5000,
            ],
        ]);

        $user = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $this->actingAs($user)
            ->post(route('api.merchant-registration.approve', $registration), [
                'merchant_mdr_percent' => 1.2,
                'base_mdr_percent' => 0.8,
                'ma_fee_percent' => 0.2,
                'agent_fee_percent' => 0.1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('merchants', ['name' => 'Approval Test Store', 'approval_status' => 'approved']);
        $merchant = Merchant::query()->where('name', 'Approval Test Store')->firstOrFail();
        $this->assertSame('hg-approval-id', $merchant->merchant_id);
        $this->assertSame('hg-approval-key', $merchant->merchant_key);
        $this->assertSame('pic-approval@paygrid.local', $merchant->pic_email);
        $this->assertSame('finance-approval@paygrid.local', $merchant->finance_email);
        $this->assertSame('cs-approval@paygrid.local', $merchant->cs_email);
        $this->assertSame('https://example.test/transaction', $merchant->transaction_callback_url);
        $this->assertSame(5000, $merchant->disbursement_fee_fixed);
        $this->assertDatabaseHas('audit_logs', ['action' => 'merchant_registration.approved']);
    }

    public function test_agent_onboarding_link_is_single_use_and_scoped_to_agent(): void
    {
        $this->seed();
        $agentUser = User::query()->where('username', 'AG-EPC')->firstOrFail();
        $agent = Agent::query()->where('code', 'AG-EPC')->firstOrFail();

        $this->actingAs($agentUser)
            ->post(route('agent.onboarding-links.store'), [
                'recipient_email' => 'merchant-link@paygrid.local',
                'recipient_telegram' => '@merchantlink',
            ])->assertRedirect()->assertSessionHas('onboarding_link');

        $link = AgentOnboardingLink::query()->firstOrFail();
        $this->assertSame($agent->id, $link->agent_id);
        $this->assertNotNull($link->expires_at);
        $this->get('/form')->assertNotFound();
        $this->get(route('merchant-registration.token-form', $link))->assertOk()->assertSee('Form Registrasi Toko')->assertSee('EPC');

        $this->post(route('merchant-registration.token-store', $link), [
            'store_name' => 'Link Store One Shot',
            'engine_name' => 'GENESIS DIGITAL',
            'merchant_type' => 'cm',
            'gateway' => 'hilogate',
            'settlement_method' => 'standard_h1',
            'finance_email' => 'finance-link@paygrid.local',
            'cs_email' => 'cs-link@paygrid.local',
        ])->assertRedirect()->assertSessionHas('status');

        $registration = MerchantRegistration::query()->where('store_name', 'Link Store One Shot')->firstOrFail();
        $this->assertSame($agent->id, $registration->agent_id);
        $this->assertSame('pending_agent', $registration->status);
        $this->assertSame('used', $link->refresh()->status);
        $this->assertSame($registration->id, $link->merchant_registration_id);

        $this->post(route('merchant-registration.token-store', $link), ['store_name' => 'Second Submit'])->assertGone();

        $this->actingAs($agentUser)->post(route('agent.onboarding-links.store'), [])->assertRedirect();
        $activeLink = AgentOnboardingLink::query()->where('status', 'active')->latest()->firstOrFail();
        $this->post(route('agent.onboarding-links.expire', $activeLink))->assertRedirect()->assertSessionHas('status');
        $this->assertSame('expired', $activeLink->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.onboarding_link_expired']);

        $this->actingAs($agentUser)->post(route('agent.onboarding-links.store'), [])->assertRedirect();
        $timedOutLink = AgentOnboardingLink::query()->where('status', 'active')->latest()->firstOrFail();
        $timedOutLink->update(['expires_at' => now()->subMinute()]);
        $this->post(route('merchant-registration.token-store', $timedOutLink), ['store_name' => 'Expired Submit'])->assertGone();

        $this->actingAs($agentUser)->post(route('agent.onboarding-links.store'), [])->assertRedirect();
        $deleteLink = AgentOnboardingLink::query()->where('status', 'active')->latest()->firstOrFail();
        $this->post(route('merchant-registration.token-store', $deleteLink), ['store_name' => 'Request To Delete'])->assertRedirect();
        $deleteRegistration = MerchantRegistration::query()->where('store_name', 'Request To Delete')->firstOrFail();
        $this->actingAs($agentUser)->delete(route('agent.requests.delete', $deleteRegistration))->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('merchant_registrations', ['id' => $deleteRegistration->id]);
        $this->assertSame('expired', $deleteLink->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.merchant_registration_deleted']);

        $this->actingAs($agentUser)
            ->post(route('api.merchant-registration.submit', $registration))
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame('pending_ma', $registration->refresh()->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $agent->ma_user_id]);
        $this->get(route('agent.requests', ['q' => 'Link Store', 'status' => 'pending_ma']))
            ->assertOk()
            ->assertSee('Link Store One Shot')
            ->assertDontSee('Request To Delete');
        $this->get(route('agent.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($agentUser)->post(route('agent.onboarding-links.store'), [])->assertRedirect();
        $bulkLink = AgentOnboardingLink::query()->where('status', 'active')->latest()->firstOrFail();
        $this->post(route('agent.onboarding-links.bulk'), ['action' => 'expire', 'link_ids' => [$bulkLink->id]])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('expired', $bulkLink->refresh()->status);

        $this->actingAs($agentUser)->post(route('agent.onboarding-links.store'), [])->assertRedirect();
        $cleanupLink = AgentOnboardingLink::query()->where('status', 'active')->latest()->firstOrFail();
        $cleanupLink->update(['expires_at' => now()->subMinute()]);
        $this->artisan('onboarding-links:expire')->assertSuccessful();
        $this->assertSame('expired', $cleanupLink->refresh()->status);
    }
}
