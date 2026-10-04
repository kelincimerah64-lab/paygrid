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
        $response->assertRedirect(route('ma.wa-tickets.show', $ticket));
        $this->assertSame('tech', $ticket->department);
        $this->assertNull($ticket->claimed_by_user_id);

        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'created');
    }

    public function test_claim_is_atomic_only_the_first_caller_wins(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $other = User::factory()->create(['role' => 'superadmin']);
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);

        $first = $this->actingAs($ma)->post(route('ma.wa-tickets.claim', $ticket));
        $first->assertRedirect();
        $this->assertSame($ma->id, $ticket->fresh()->claimed_by_user_id);

        $second = $this->actingAs($other)->post(route('ma.wa-tickets.claim', $ticket));
        $second->assertSessionHas('status', 'Tiket ini sudah diambil orang lain duluan.');
        $this->assertSame($ma->id, $ticket->fresh()->claimed_by_user_id);
    }

    public function test_reply_respects_the_internal_flag(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);

        $this->actingAs($ma)->post(route('ma.wa-tickets.reply', $ticket), [
            'body' => 'Update buat toko.',
            'is_internal' => '0',
        ])->assertRedirect();

        $this->actingAs($ma)->post(route('ma.wa-tickets.reply', $ticket), [
            'body' => 'Diskusi internal doang.',
            'is_internal' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('merchant_ticket_messages', ['body' => 'Update buat toko.', 'is_internal' => false]);
        $this->assertDatabaseHas('merchant_ticket_messages', ['body' => 'Diskusi internal doang.', 'is_internal' => true]);

        $show = $this->actingAs($ma)->get(route('ma.wa-tickets.show', $ticket));
        $show->assertSee('Update buat toko.')->assertSee('Diskusi internal doang.');
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

        $this->actingAs($ma)->post(route('ma.wa-tickets.transfer', $ticket), [
            'to_user_id' => $target->id,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($target->id, $ticket->claimed_by_user_id);
        $this->assertDatabaseHas('merchant_ticket_messages', ['merchant_ticket_id' => $ticket->id, 'is_internal' => true]);
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'transferred');
    }

    public function test_close_sets_status_and_optional_note_is_customer_visible(): void
    {
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Test.',
        ]);

        $this->actingAs($ma)->post(route('ma.wa-tickets.close', $ticket), [
            'note' => 'Sudah diperbaiki, silakan dicek ulang.',
        ])->assertRedirect(route('ma.wa-tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertDatabaseHas('merchant_ticket_messages', ['merchant_ticket_id' => $ticket->id, 'body' => 'Sudah diperbaiki, silakan dicek ulang.', 'is_internal' => false]);
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

        $this->actingAs($ma)->get(route('ma.wa-tickets.show', $ticket))->assertForbidden();
        $this->actingAs($ma)->post(route('ma.wa-tickets.claim', $ticket))->assertForbidden();
    }

    public function test_reminder_command_notifies_once_for_unclaimed_tickets_past_threshold(): void
    {
        Bus::fake();
        $this->seed();
        $merchant = $this->pilotMerchant();
        $ma = $this->ma();
        $ticket = app(\App\Services\MerchantTicketService::class)->create($merchant, $ma, [
            'department' => 'cs', 'category' => 'others', 'description' => 'Belum diambil.',
        ]);
        $ticket->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->artisan('wa-tickets:remind-unclaimed')->assertSuccessful();
        Bus::assertDispatched(NotifyWaTicketLink::class, fn ($job) => $job->ticketId === $ticket->id && $job->event === 'reminder');

        Bus::fake();
        $this->artisan('wa-tickets:remind-unclaimed')->assertSuccessful();
        Bus::assertNotDispatched(NotifyWaTicketLink::class);
    }
}
