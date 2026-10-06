<?php

namespace Tests\Feature;

use App\Jobs\NotifyWaTicketLink;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MaWaTicketTest extends TestCase
{
    use RefreshDatabase;

    private function pilotMerchant(): Merchant
    {
        $merchant = Merchant::query()->where('slug', 'valohoki-1lg')->firstOrFail();
        $merchant->forceFill(['general_ticket_enabled' => true])->save();

        return $merchant;
    }

    private function ma(): User
    {
        return User::query()->where('email', 'michael@paygrid.local')->firstOrFail();
    }

    private function approver(): User
    {
        return User::factory()->create(['role' => 'approver', 'is_active' => true]);
    }

    public function test_ma_can_create_a_wa_ticket_and_a_notify_job_is_dispatched(): void
    {
        Bus::fake();
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();

        $response = $this->actingAs($ma)->post(route('ma.wa-tickets.store'), [
            'merchant_id' => $merchant->id,
            'department' => 'tech',
            'category' => 'technical_issue',
            'description' => 'Dashboard lemot pas jam sibuk.',
        ]);

        $ticket = MerchantTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $response->assertRedirect(route('wa-tickets.show', $ticket));
        $this->assertSame('tech', $ticket->department);
        $this->assertNull($ticket->claimed_by_user_id);

        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'created');
    }

    public function test_claim_is_atomic_only_the_first_caller_wins(): void
    {
        Bus::fake();
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $other = User::factory()->create(['role' => 'superadmin']);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);

        $first = $this->actingAs($ma)->post(route('wa-tickets.claim', $ticket));
        $first->assertRedirect();
        $this->assertSame($ma->id, $ticket->fresh()->claimed_by_user_id);
        Bus::assertDispatchedTimes(NotifyWaTicketLink::class, 1, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'claimed');

        $second = $this->actingAs($other)->post(route('wa-tickets.claim', $ticket));
        $second->assertSessionHas('status', 'Tiket ini sudah diambil orang lain duluan.');
        $this->assertSame($ma->id, $ticket->fresh()->claimed_by_user_id);
        Bus::assertDispatchedTimes(NotifyWaTicketLink::class, 1, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'claimed');
    }

    public function test_reply_respects_the_internal_flag(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);
        app(\App\Services\MerchantTicketService::class)->claim($ticket, $ma);

        $this->actingAs($ma)->post(route('wa-tickets.reply', $ticket), [
            'body' => 'Update buat toko.',
            'is_internal' => '0',
        ])->assertRedirect();

        $this->actingAs($ma)->post(route('wa-tickets.reply', $ticket), [
            'body' => 'Diskusi internal doang.',
            'is_internal' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('merchant_ticket_messages', ['body' => 'Update buat toko.', 'is_internal' => false]);
        $this->assertDatabaseHas('merchant_ticket_messages', ['body' => 'Diskusi internal doang.', 'is_internal' => true]);

        $show = $this->actingAs($ma)->get(route('wa-tickets.show', $ticket));
        $show->assertSee('Update buat toko.')->assertSee('Diskusi internal doang.');
    }

    public function test_reply_to_toko_is_blocked_until_the_ticket_is_claimed(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);

        $this->actingAs($ma)->post(route('wa-tickets.reply', $ticket), [
            'body' => 'Belum diambil tapi coba kirim ke toko.',
            'is_internal' => '0',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('merchant_ticket_messages', ['body' => 'Belum diambil tapi coba kirim ke toko.']);

        // Internal discussion is fine either way - no claim required.
        $this->actingAs($ma)->post(route('wa-tickets.reply', $ticket), [
            'body' => 'Diskusi internal sebelum diambil.',
            'is_internal' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('merchant_ticket_messages', ['body' => 'Diskusi internal sebelum diambil.']);
    }

    public function test_transfer_reassigns_claim_and_dispatches_notify_job(): void
    {
        Bus::fake();
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $target = User::factory()->create(['role' => 'cs_pusat', 'name' => 'Dimas', 'is_active' => true]);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'tech', 'category' => 'technical_issue', 'description' => 'Test.',
        ]);
        app(\App\Services\MerchantTicketService::class)->claim($ticket, $ma);

        $this->actingAs($ma)->post(route('wa-tickets.transfer', $ticket), [
            'to_user_id' => $target->id,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($target->id, $ticket->claimed_by_user_id);
        $this->assertDatabaseHas('merchant_ticket_messages', ['merchant_ticket_id' => $ticket->id, 'is_internal' => true]);
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'transferred');
    }

    public function test_close_sets_status_and_optional_note_is_customer_visible(): void
    {
        Bus::fake();
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);

        $this->actingAs($ma)->post(route('wa-tickets.close', $ticket), [
            'note' => 'Sudah diperbaiki, silakan dicek ulang.',
        ])->assertRedirect(route('wa-tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame($ma->id, $ticket->closed_by_user_id);
        $this->assertDatabaseHas('merchant_ticket_messages', ['merchant_ticket_id' => $ticket->id, 'body' => 'Sudah diperbaiki, silakan dicek ulang.', 'is_internal' => false]);
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'closed');
    }

    public function test_ma_cannot_reach_a_ticket_outside_their_own_agents(): void
    {
        $this->seed();
        $ma = $this->ma();
        $otherMa = User::query()->create(['name' => 'Other MA', 'email' => 'other-ma-wa@paygrid.local', 'role' => 'ma', 'password' => Hash::make('secret123')]);
        $otherAgent = Agent::query()->create(['code' => 'AGN-OTHER-WA', 'name' => 'Other Agent WA', 'ma_user_id' => $otherMa->id]);
        $otherMerchant = Merchant::query()->create(['agent_id' => $otherAgent->id, 'slug' => 'other-wa-store', 'name' => 'Toko Lain', 'merchant_type' => 'cm', 'gateway' => 'hilogate', 'approval_status' => 'approved']);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($otherMerchant, $otherMa, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Punya MA lain.',
        ]);

        $this->actingAs($ma)->get(route('wa-tickets.show', $ticket))->assertForbidden();
        $this->actingAs($ma)->post(route('wa-tickets.claim', $ticket))->assertForbidden();
    }

    public function test_cs_pusat_can_open_and_claim_a_ticket_via_its_wa_link(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $cs = User::factory()->create(['role' => 'cs_pusat', 'is_active' => true]);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Dari link WA.',
        ]);

        $this->actingAs($cs)->get(route('wa-tickets.show', $ticket))->assertOk()->assertSee('CS Pusat');
        $this->actingAs($cs)->post(route('wa-tickets.claim', $ticket))->assertRedirect();

        $this->assertSame($cs->id, $ticket->fresh()->claimed_by_user_id);
    }

    public function test_topup_saldo_ticket_requires_approval_and_notifies_the_approval_group(): void
    {
        Bus::fake();
        config(['paygrid.whatsapp.approval_group_id' => 'approval-group@g.us', 'paygrid.whatsapp.handling_group_id' => 'handling-group@g.us']);
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();

        $response = $this->actingAs($ma)->post(route('ma.wa-tickets.store'), [
            'merchant_id' => $merchant->id,
            'department' => 'finance',
            'category' => 'topup_saldo',
            'description' => 'Minta topup saldo.',
            'nominal' => '5000000',
        ]);

        $ticket = MerchantTicket::query()->where('merchant_id', $merchant->id)->firstOrFail();
        $response->assertRedirect(route('wa-tickets.show', $ticket));
        $this->assertSame('waiting', $ticket->approval_status);
        $this->assertSame(['nominal' => '5000000'], $ticket->metadata);

        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'created' && $job->groupId === 'approval-group@g.us');
    }

    public function test_claim_is_blocked_while_waiting_for_approval(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'finance', 'category' => 'topup_saldo', 'description' => 'Test.',
        ]);
        $ticket->forceFill(['approval_status' => 'waiting'])->save();

        $this->actingAs($ma)->post(route('wa-tickets.claim', $ticket))->assertStatus(422);
        $this->assertNull($ticket->fresh()->claimed_by_user_id);
    }

    public function test_approve_notifies_handling_group_and_unblocks_claim(): void
    {
        Bus::fake();
        config(['paygrid.whatsapp.handling_group_id' => 'handling-group@g.us']);
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $approver = $this->approver();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'finance', 'category' => 'topdown_saldo', 'description' => 'Test.',
        ]);
        $ticket->forceFill(['approval_status' => 'waiting'])->save();

        $this->actingAs($approver)->post(route('wa-tickets.approve', $ticket))->assertRedirect();

        $ticket->refresh();
        $this->assertSame('approved', $ticket->approval_status);
        $this->assertSame($approver->name, $ticket->approval_by);
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'approved' && $job->groupId === 'handling-group@g.us');

        $this->actingAs($ma)->post(route('wa-tickets.claim', $ticket))->assertRedirect();
        $this->assertSame($ma->id, $ticket->fresh()->claimed_by_user_id);
    }

    public function test_reject_closes_the_ticket_with_a_note(): void
    {
        Bus::fake();
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $approver = $this->approver();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'tech', 'category' => 'ip_whitelist', 'description' => 'Test.',
        ]);
        $ticket->forceFill(['approval_status' => 'waiting'])->save();

        $this->actingAs($approver)->post(route('wa-tickets.reject', $ticket), [
            'note' => 'Server belum terverifikasi.',
        ])->assertRedirect(route('wa-tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame('rejected', $ticket->approval_status);
        $this->assertSame('closed', $ticket->status);
        $this->assertSame('Server belum terverifikasi.', $ticket->approval_note);
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'rejected');
    }

    public function test_ma_and_cs_pusat_cannot_approve_or_reject(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $cs = User::factory()->create(['role' => 'cs_pusat', 'is_active' => true]);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'tech', 'category' => 'ip_whitelist', 'description' => 'Test.',
        ]);
        $ticket->forceFill(['approval_status' => 'waiting'])->save();

        $this->actingAs($ma)->post(route('wa-tickets.approve', $ticket))->assertForbidden();
        $this->actingAs($cs)->post(route('wa-tickets.approve', $ticket))->assertForbidden();
        $this->actingAs($ma)->post(route('wa-tickets.reject', $ticket))->assertForbidden();
        $this->assertSame('waiting', $ticket->fresh()->approval_status);
    }

    public function test_approver_cannot_approve_their_own_ticket(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $approver = $this->approver();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $approver, [
            'department' => 'tech', 'category' => 'ip_whitelist', 'description' => 'Test.',
        ]);
        $ticket->forceFill(['approval_status' => 'waiting'])->save();

        $this->actingAs($approver)->post(route('wa-tickets.approve', $ticket))->assertForbidden();
        $this->assertSame('waiting', $ticket->fresh()->approval_status);
    }

    public function test_approver_cannot_claim_reply_transfer_or_close(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $approver = $this->approver();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);

        $this->actingAs($approver)->post(route('wa-tickets.claim', $ticket))->assertForbidden();
        $this->actingAs($approver)->post(route('wa-tickets.reply', $ticket), ['body' => 'x'])->assertForbidden();
        $this->actingAs($approver)->post(route('wa-tickets.transfer', $ticket))->assertForbidden();
        $this->actingAs($approver)->post(route('wa-tickets.close', $ticket))->assertForbidden();
    }

    public function test_approver_pending_approvals_page_lists_only_waiting_tickets_company_wide(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $approver = $this->approver();
        $waiting = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'tech', 'category' => 'ip_whitelist', 'description' => 'Butuh approval.',
        ]);
        $waiting->forceFill(['approval_status' => 'waiting'])->save();
        $notWaiting = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Tidak butuh approval.',
        ]);

        $response = $this->actingAs($approver)->get(route('wa-tickets.pending'));

        $response->assertOk()->assertSee($waiting->ticket_no)->assertDontSee($notWaiting->ticket_no);
    }

    public function test_reminder_command_advances_through_three_stages_then_stops(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Belum diambil.',
        ]);
        $ticket->forceFill(['wa_reminder_stage_at' => now()->subMinutes(11)])->save();

        Bus::fake();
        $this->artisan('wa-tickets:remind-unclaimed')->assertSuccessful();
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'reminder' && $job->reminderStage === 1);
        $this->assertSame(1, $ticket->fresh()->wa_reminder_stage);

        $ticket->fresh()->forceFill(['wa_reminder_stage_at' => now()->subMinutes(11)])->save();
        Bus::fake();
        $this->artisan('wa-tickets:remind-unclaimed')->assertSuccessful();
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'reminder' && $job->reminderStage === 2);
        $this->assertSame(2, $ticket->fresh()->wa_reminder_stage);

        $ticket->fresh()->forceFill(['wa_reminder_stage_at' => now()->subMinutes(11)])->save();
        Bus::fake();
        $this->artisan('wa-tickets:remind-unclaimed')->assertSuccessful();
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'reminder' && $job->reminderStage === 3);
        $this->assertSame(3, $ticket->fresh()->wa_reminder_stage);

        // Stage 3 is the last one - a 4th cycle must not fire again.
        $ticket->fresh()->forceFill(['wa_reminder_stage_at' => now()->subMinutes(11)])->save();
        Bus::fake();
        $this->artisan('wa-tickets:remind-unclaimed')->assertSuccessful();
        Bus::assertNotDispatched(NotifyWaTicketLink::class);
    }

    public function test_notify_job_maintains_a_single_live_card_through_the_ticket_lifecycle(): void
    {
        config([
            'paygrid.whatsapp.api_url' => 'http://waha.test',
            'paygrid.whatsapp.api_key' => 'test-key',
        ]);
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test siklus card.',
        ]);

        $wa = $this->mock(\App\Services\WhatsApp\WhatsAppNotifier::class);

        $wa->shouldReceive('send')->once()
            ->with('handling-group@g.us', \Mockery::any())
            ->andReturn('msg-created');
        NotifyWaTicketLink::dispatch($ticket->id, 'created', 'handling-group@g.us');
        $ticket->refresh();
        $this->assertSame('msg-created', $ticket->wa_active_message_id);
        $this->assertSame('handling-group@g.us', $ticket->wa_active_chat_id);

        $wa->shouldReceive('delete')->once()->with('handling-group@g.us', 'msg-created')->andReturn(true);
        $wa->shouldReceive('send')->once()
            ->with('handling-group@g.us', \Mockery::on(fn ($text) => str_contains($text, 'Reminder 1')))
            ->andReturn('msg-reminder1');
        NotifyWaTicketLink::dispatch($ticket->id, 'reminder', 'handling-group@g.us', 1);
        $ticket->refresh();
        $this->assertSame('msg-reminder1', $ticket->wa_active_message_id);

        app(\App\Services\MerchantTicketService::class)->claim($ticket, $ma);
        $wa->shouldReceive('edit')->once()
            ->with('handling-group@g.us', 'msg-reminder1', \Mockery::on(fn ($text) => str_contains($text, 'Diambil oleh '.$ma->name)))
            ->andReturn(true);
        NotifyWaTicketLink::dispatch($ticket->id, 'claimed', '');

        app(\App\Services\MerchantTicketService::class)->closeWithNote($ticket->fresh(), $ma, null);
        $wa->shouldReceive('edit')->once()
            ->with('handling-group@g.us', 'msg-reminder1', \Mockery::on(fn ($text) => str_contains($text, 'CLOSED oleh '.$ma->name)))
            ->andReturn(true);
        NotifyWaTicketLink::dispatch($ticket->id, 'closed', '');
    }

    public function test_notify_job_on_approval_edits_the_approval_card_and_opens_a_fresh_handling_card(): void
    {
        config([
            'paygrid.whatsapp.api_url' => 'http://waha.test',
            'paygrid.whatsapp.api_key' => 'test-key',
        ]);
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'tech', 'category' => 'ip_whitelist', 'description' => 'Butuh approval.',
        ]);
        $ticket->forceFill([
            'approval_status' => 'approved',
            'approval_by' => 'Approver Test',
            'wa_active_chat_id' => 'approval-group@g.us',
            'wa_active_message_id' => 'msg-approval-card',
        ])->save();

        $wa = $this->mock(\App\Services\WhatsApp\WhatsAppNotifier::class);
        $wa->shouldReceive('edit')->once()
            ->with('approval-group@g.us', 'msg-approval-card', \Mockery::on(fn ($text) => str_contains($text, 'Disetujui oleh Approver Test')))
            ->andReturn(true);
        $wa->shouldReceive('send')->once()
            ->with('handling-group@g.us', \Mockery::any())
            ->andReturn('msg-handling-card');

        NotifyWaTicketLink::dispatch($ticket->id, 'approved', 'handling-group@g.us');

        $ticket->refresh();
        $this->assertSame('msg-handling-card', $ticket->wa_active_message_id);
        $this->assertSame('handling-group@g.us', $ticket->wa_active_chat_id);
        $this->assertSame(0, $ticket->wa_reminder_stage);
    }

    public function test_staff_can_edit_their_own_message_but_not_a_teammates(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $other = User::factory()->create(['role' => 'cs_pusat', 'is_active' => true]);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);
        $message = app(\App\Services\MerchantTicketService::class)->addMessage($ticket, $ma, 'Diskusi awal.', true, true);

        $this->actingAs($ma)->patch(route('wa-tickets.messages.update', [$ticket, $message]), ['body' => 'Diskusi awal (diperbaiki).'])
            ->assertRedirect();
        $message->refresh();
        $this->assertSame('Diskusi awal (diperbaiki).', $message->body);
        $this->assertNotNull($message->edited_at);

        $this->actingAs($other)->patch(route('wa-tickets.messages.update', [$ticket, $message]), ['body' => 'Coba edit punya orang lain.'])
            ->assertForbidden();
        $this->assertSame('Diskusi awal (diperbaiki).', $message->fresh()->body);
    }

    public function test_show_records_a_view_and_read_by_reflects_who_has_seen_each_message(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $cs = User::factory()->create(['role' => 'cs_pusat', 'name' => 'Rama CS', 'is_active' => true]);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);
        app(\App\Services\MerchantTicketService::class)->addMessage($ticket, $ma, 'Pesan pertama.', true, true);

        // Nobody else has viewed yet.
        $this->actingAs($ma)->get(route('wa-tickets.show', $ticket))->assertOk()->assertDontSee('Dibaca:');

        // CS opens the ticket - now the MA's own next load should show "read by Rama CS".
        $this->actingAs($cs)->get(route('wa-tickets.show', $ticket))->assertOk();
        $this->assertDatabaseHas('merchant_ticket_views', ['merchant_ticket_id' => $ticket->id, 'user_id' => $cs->id]);

        $this->actingAs($ma)->get(route('wa-tickets.show', $ticket))->assertOk()->assertSee('Rama CS');
    }
}
