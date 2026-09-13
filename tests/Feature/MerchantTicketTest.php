<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MerchantTicketTest extends TestCase
{
    use RefreshDatabase;

    private function pilotMerchant(): Merchant
    {
        $merchant = Merchant::query()->where('slug', 'valohoki-1lg')->firstOrFail();
        $merchant->forceFill(['general_ticket_enabled' => true])->save();

        return $merchant;
    }

    public function test_admin_can_create_cs_ticket_on_pilot_merchant(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $response = $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'cs',
            'category' => 'topup',
            'description' => 'Topup belum masuk ke saldo toko.',
        ]);

        $ticket = MerchantTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $response->assertRedirect(route('merchant.tickets.show', [$merchant, $ticket]));
        $this->assertSame('cs', $ticket->department);
        $this->assertSame('topup', $ticket->category);
        $this->assertSame('open', $ticket->status);
        $this->assertNotNull($ticket->ticket_no);
    }

    public function test_tech_department_ticket_can_be_created(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'tech',
            'category' => 'ip_whitelist',
            'description' => 'Butuh whitelist IP VPS baru.',
        ])->assertRedirect();

        $this->assertDatabaseHas('merchant_tickets', [
            'merchant_id' => $merchant->id,
            'department' => 'tech',
            'category' => 'ip_whitelist',
        ]);
    }

    public function test_finance_department_ticket_can_be_created(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'finance',
            'category' => 'discrepancies_amount',
            'description' => 'Ada selisih nominal settlement.',
        ])->assertRedirect();

        $this->assertDatabaseHas('merchant_tickets', [
            'merchant_id' => $merchant->id,
            'department' => 'finance',
            'category' => 'discrepancies_amount',
        ]);
    }

    public function test_category_must_match_department(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'cs',
            'category' => 'ip_whitelist',
            'description' => 'Kategori tech dikirim ke department cs.',
        ])->assertSessionHasErrors('category');
    }

    public function test_merchants_get_the_ticket_feature_by_default(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $admin = User::query()->where('email', 'admin@nnp-cm-bj.local')->firstOrFail();

        $this->assertTrue($merchant->general_ticket_enabled);
        $this->actingAs($admin)->get(route('merchant.tickets.index', $merchant))->assertOk();
        $this->actingAs($admin)->get(route('merchant.admin.users', $merchant))->assertSee('Create Ticket');
    }

    public function test_menu_hides_create_ticket_when_explicitly_disabled(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $merchant->forceFill(['general_ticket_enabled' => false])->save();
        $admin = User::query()->where('email', 'admin@nnp-cm-bj.local')->firstOrFail();

        $this->actingAs($admin)->get(route('merchant.admin.users', $merchant))->assertDontSee('Create Ticket');
        $this->actingAs($admin)->get(route('merchant.tickets.index', $merchant))->assertNotFound();
    }

    public function test_cs_pusat_sees_both_departments_by_default_and_can_filter(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);
        $csPusat = User::query()->where('email', 'cs-pusat@paygrid.local')->firstOrFail();

        $csTicket = MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $admin->id,
            'ticket_no' => 'TK-00001',
            'department' => 'cs',
            'category' => 'topup',
            'description' => 'cs ticket',
            'last_message_at' => now(),
        ]);
        $techTicket = MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $admin->id,
            'ticket_no' => 'TK-00002',
            'department' => 'tech',
            'category' => 'ip_whitelist',
            'description' => 'tech ticket',
            'last_message_at' => now(),
        ]);
        $financeTicket = MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $admin->id,
            'ticket_no' => 'TK-00005',
            'department' => 'finance',
            'category' => 'missing_transaction',
            'description' => 'finance ticket',
            'last_message_at' => now(),
        ]);

        $this->actingAs($csPusat)->get(route('dept-tickets.index'))
            ->assertSee('TK-00001')->assertSee('TK-00002')->assertSee('TK-00005');
        $this->actingAs($csPusat)->get(route('dept-tickets.index', ['department' => 'cs']))
            ->assertSee('TK-00001')->assertDontSee('TK-00002')->assertDontSee('TK-00005');
        $this->actingAs($csPusat)->get(route('dept-tickets.index', ['department' => 'finance']))
            ->assertSee('TK-00005')->assertDontSee('TK-00001')->assertDontSee('TK-00002');
        $this->actingAs($csPusat)->get(route('dept-tickets.show', $techTicket))->assertOk();
        $this->actingAs($csPusat)->get(route('dept-tickets.show', $csTicket))->assertOk();
        $this->actingAs($csPusat)->get(route('dept-tickets.show', $financeTicket))->assertOk();
    }

    public function test_superadmin_can_view_both_departments(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);
        $superadmin = User::query()->where('email', 'superadmin@paygrid.local')->firstOrFail();

        $ticket = MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $admin->id,
            'ticket_no' => 'TK-00003',
            'department' => 'tech',
            'category' => 'technical_issue',
            'description' => 'tech ticket',
            'last_message_at' => now(),
        ]);

        $this->actingAs($superadmin)->get(route('dept-tickets.index'))->assertOk()->assertSee('TK-00003');
        $this->actingAs($superadmin)->get(route('dept-tickets.show', $ticket))->assertOk();
    }

    public function test_two_way_thread_and_status_transition(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);
        $csPusat = User::query()->where('email', 'cs-pusat@paygrid.local')->firstOrFail();

        $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'cs',
            'category' => 'settlement',
            'description' => 'Settlement belum masuk.',
        ]);
        $ticket = MerchantTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $this->assertSame('open', $ticket->fresh()->status);

        $this->actingAs($csPusat)->post(route('dept-tickets.reply', $ticket), ['body' => 'Sedang kami cek ya.'])
            ->assertRedirect();
        $this->assertSame('in_progress', $ticket->fresh()->status);

        $this->actingAs($admin)->post(route('merchant.tickets.reply', [$merchant, $ticket]), ['body' => 'Terima kasih, ditunggu.'])
            ->assertRedirect();

        $this->assertDatabaseCount('merchant_ticket_messages', 2);
        $thread = $this->actingAs($admin)->get(route('merchant.tickets.show', [$merchant, $ticket]));
        $thread->assertSee('Sedang kami cek ya.')->assertSee('Terima kasih, ditunggu.');
    }

    public function test_closed_ticket_rejects_store_reply_until_reopened(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);
        $csPusat = User::query()->where('email', 'cs-pusat@paygrid.local')->firstOrFail();

        $ticket = MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $admin->id,
            'ticket_no' => 'TK-00004',
            'department' => 'cs',
            'category' => 'others',
            'description' => 'closed ticket test',
            'status' => 'closed',
            'closed_at' => now(),
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('merchant.tickets.reply', [$merchant, $ticket]), ['body' => 'halo?'])
            ->assertStatus(422);

        $this->actingAs($csPusat)->post(route('dept-tickets.status', $ticket), ['status' => 'open'])->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);

        $this->actingAs($admin)->post(route('merchant.tickets.reply', [$merchant, $ticket]), ['body' => 'sekarang bisa'])
            ->assertRedirect();
    }

    public function test_ma_sees_pilot_merchant_and_can_create_ticket_for_it(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();

        $this->actingAs($ma)->get(route('ma.tickets.index'))
            ->assertOk()
            ->assertSee($merchant->name)
            ->assertSee(route('merchant.tickets.index', $merchant));

        $this->actingAs($ma)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'cs',
            'category' => 'others',
            'description' => 'Dibuat oleh MA untuk toko.',
        ])->assertRedirect();

        $this->assertDatabaseHas('merchant_tickets', ['merchant_id' => $merchant->id, 'created_by_user_id' => $ma->id]);
    }

    public function test_ma_keeps_their_own_menu_when_visiting_a_stores_ticket_page(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $storeAdmin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $this->actingAs($ma)->get(route('merchant.tickets.index', $merchant))
            ->assertOk()
            ->assertSee('Request Approval')
            ->assertDontSee('Atur Minimum Topup');

        $this->actingAs($storeAdmin)->get(route('merchant.tickets.index', $merchant))
            ->assertOk()
            ->assertSee('Atur Minimum Topup')
            ->assertDontSee('Request Approval');
    }

    public function test_ma_ticket_list_excludes_merchants_explicitly_disabled(): void
    {
        $this->seed();
        $this->pilotMerchant();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $bj = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $bj->forceFill(['general_ticket_enabled' => false])->save();

        $this->actingAs($ma)->get(route('ma.tickets.index'))
            ->assertOk()
            ->assertDontSee(route('merchant.tickets.index', $bj));
    }

    public function test_agent_sees_only_their_own_scoped_merchant_and_can_create_ticket(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $agentUser = User::query()->where('username', 'AG-OTHER')->firstOrFail();
        $bj = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();

        $this->actingAs($agentUser)->get(route('ma.tickets.index'))
            ->assertOk()
            ->assertSee($merchant->name)
            ->assertDontSee(route('merchant.tickets.index', $bj));

        $this->actingAs($agentUser)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'tech',
            'category' => 'technical_issue',
            'description' => 'Dibuat oleh agen untuk toko.',
        ])->assertRedirect();

        $this->assertDatabaseHas('merchant_tickets', ['merchant_id' => $merchant->id, 'created_by_user_id' => $agentUser->id]);
    }

    public function test_ma_ticket_list_shows_open_issue_summary_per_store(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();

        MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $ma->id,
            'ticket_no' => 'TK-00099',
            'department' => 'cs',
            'category' => 'settlement',
            'description' => 'Settlement belum cair',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->actingAs($ma)->get(route('ma.tickets.index'))
            ->assertOk()
            ->assertSee('TK-00099')
            ->assertSee('Settlement');
    }

    public function test_ma_ticket_list_stays_compact_with_many_open_tickets(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();

        for ($i = 1; $i <= 5; $i++) {
            MerchantTicket::query()->create([
                'merchant_id' => $merchant->id,
                'created_by_user_id' => $ma->id,
                'ticket_no' => 'TK-STACK-'.$i,
                'department' => 'cs',
                'category' => 'others',
                'description' => 'Tiket ke-'.$i,
                'status' => 'open',
                'last_message_at' => now()->addSeconds($i),
            ]);
        }

        $response = $this->actingAs($ma)->get(route('ma.tickets.index'))->assertOk();
        $response->assertSee('5 terbuka');
        $response->assertSee('TK-STACK-5');
        $response->assertDontSee('TK-STACK-4');
    }

    public function test_cs_pusat_sidebar_shows_a_badge_for_open_manual_tickets(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);
        $csPusat = User::query()->where('email', 'cs-pusat@paygrid.local')->firstOrFail();

        $this->actingAs($csPusat)->get(route('center-support.tickets'))->assertDontSee('class="nav-badge"', false);

        MerchantTicket::query()->create([
            'merchant_id' => $merchant->id,
            'created_by_user_id' => $admin->id,
            'ticket_no' => 'TK-BADGE-1',
            'department' => 'cs',
            'category' => 'others',
            'description' => 'butuh badge',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($csPusat)->get(route('center-support.tickets'))->assertOk();
        $response->assertSee('class="nav-badge"', false);
        $response->assertSee('Manual Tickets');
    }

    public function test_ticket_can_be_created_with_up_to_three_attachments(): void
    {
        Storage::fake('local');
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'cs',
            'category' => 'others',
            'description' => 'Ada 2 bukti transfer.',
            'attachments' => [
                UploadedFile::fake()->image('bukti-1.jpg'),
                UploadedFile::fake()->image('bukti-2.jpg'),
            ],
        ])->assertRedirect();

        $ticket = MerchantTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $this->assertCount(2, $ticket->attachments);
        Storage::disk('local')->assertExists($ticket->attachments[0]['path']);
        Storage::disk('local')->assertExists($ticket->attachments[1]['path']);

        $this->actingAs($admin)->get(route('merchant.tickets.attachment', [$merchant, $ticket, 0]))->assertOk();
        $this->actingAs($admin)->get(route('merchant.tickets.attachment', [$merchant, $ticket, 1]))->assertOk();
        $this->actingAs($admin)->get(route('merchant.tickets.attachment', [$merchant, $ticket, 2]))->assertNotFound();
    }

    public function test_ticket_creation_rejects_more_than_three_attachments(): void
    {
        Storage::fake('local');
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);

        $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'cs',
            'category' => 'others',
            'description' => 'Kebanyakan lampiran.',
            'attachments' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'),
                UploadedFile::fake()->image('d.jpg'),
            ],
        ])->assertSessionHasErrors('attachments');
    }
}
