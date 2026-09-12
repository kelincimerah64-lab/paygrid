<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_non_pilot_merchant_cannot_access_ticket_feature(): void
    {
        $this->seed();
        $merchant = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();
        $admin = User::query()->where('email', 'admin@nnp-cm-bj.local')->firstOrFail();

        $this->actingAs($admin)->get(route('merchant.tickets.index', $merchant))->assertNotFound();
    }

    public function test_menu_only_shows_create_ticket_for_pilot_merchant(): void
    {
        $this->seed();
        $pilot = $this->pilotMerchant();
        $pilotAdmin = User::factory()->create(['role' => 'admin', 'merchant_id' => $pilot->id]);
        $otherAdmin = User::query()->where('email', 'admin@nnp-cm-bj.local')->firstOrFail();

        $this->actingAs($pilotAdmin)->get(route('merchant.admin.users', $pilot))->assertSee('Create Ticket');
        $this->actingAs($otherAdmin)->get(route('merchant.admin.users', $otherAdmin->merchant))->assertDontSee('Create Ticket');
    }

    public function test_cs_support_and_tech_support_are_scoped_to_their_own_department(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $admin = User::factory()->create(['role' => 'admin', 'merchant_id' => $merchant->id]);
        $csSupport = User::factory()->create(['role' => 'cs_support']);
        $techSupport = User::factory()->create(['role' => 'tech_support']);

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

        $this->actingAs($csSupport)->get(route('dept-tickets.index'))->assertSee('TK-00001')->assertDontSee('TK-00002');
        $this->actingAs($csSupport)->get(route('dept-tickets.show', $techTicket))->assertForbidden();
        $this->actingAs($techSupport)->get(route('dept-tickets.index'))->assertSee('TK-00002')->assertDontSee('TK-00001');
        $this->actingAs($techSupport)->get(route('dept-tickets.show', $csTicket))->assertForbidden();
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
        $csSupport = User::factory()->create(['role' => 'cs_support']);

        $this->actingAs($admin)->post(route('merchant.tickets.store', $merchant), [
            'department' => 'cs',
            'category' => 'settlement',
            'description' => 'Settlement belum masuk.',
        ]);
        $ticket = MerchantTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $this->assertSame('open', $ticket->fresh()->status);

        $this->actingAs($csSupport)->post(route('dept-tickets.reply', $ticket), ['body' => 'Sedang kami cek ya.'])
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
        $csSupport = User::factory()->create(['role' => 'cs_support']);

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

        $this->actingAs($csSupport)->post(route('dept-tickets.status', $ticket), ['status' => 'open'])->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);

        $this->actingAs($admin)->post(route('merchant.tickets.reply', [$merchant, $ticket]), ['body' => 'sekarang bisa'])
            ->assertRedirect();
    }

    public function test_login_redirects_new_roles_to_department_dashboard(): void
    {
        $this->seed();
        $csSupport = User::factory()->create(['role' => 'cs_support', 'password' => bcrypt('password')]);

        $this->post('/login', ['email' => $csSupport->email, 'password' => 'password'])
            ->assertRedirect(route('dept-tickets.index'));
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

    public function test_ma_ticket_list_excludes_merchants_without_the_flag(): void
    {
        $this->seed();
        $this->pilotMerchant();
        $ma = User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
        $bj = Merchant::query()->where('slug', 'nnp-cm-bj')->firstOrFail();

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
}
