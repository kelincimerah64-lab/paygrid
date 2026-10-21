<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('paygrid.telegram_broadcast.bot_token', 'test-token');
        config()->set('paygrid.telegram_broadcast.groups', 'Grup CS:-1001,Grup MA:-1002,Grup Ops:-1003');
    }

    public function test_superadmin_sees_configured_groups(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));

        $this->get(route('superadmin.page', 'broadcast-telegram'))
            ->assertOk()
            ->assertSee('Grup CS')
            ->assertSee('Grup MA')
            ->assertSee('Grup Ops');
    }

    public function test_broadcast_is_sent_only_to_selected_groups_and_audited(): void
    {
        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::sequence()
                ->push(['ok' => true])
                ->push(['ok' => false, 'description' => 'Forbidden: bot was kicked'], 403),
        ]);
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($superadmin);

        $this->post(route('superadmin.broadcast-telegram.send'), [
            'message' => 'Maintenance jam 22:00 *penting*',
            'chat_ids' => ['-1001', '-1003'],
        ])->assertRedirect()->assertSessionHas('status', 'Broadcast Telegram - Grup CS: terkirim, Grup Ops: gagal.');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['chat_id'] === '-1001' && $request['text'] === 'Maintenance jam 22:00 *penting*');
        Http::assertSent(fn ($request) => $request['chat_id'] === '-1003');
        Http::assertNotSent(fn ($request) => $request['chat_id'] === '-1002');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'telegram.broadcast_sent',
            'actor_user_id' => $superadmin->id,
        ]);
    }

    public function test_unknown_chat_id_is_rejected(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));

        $this->post(route('superadmin.broadcast-telegram.send'), [
            'message' => 'Halo',
            'chat_ids' => ['-9999'],
        ])->assertSessionHasErrors('chat_ids.0');

        Http::assertNothingSent();
    }

    public function test_non_superadmin_cannot_broadcast(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'ma']));

        $this->post(route('superadmin.broadcast-telegram.send'), [
            'message' => 'Halo',
            'chat_ids' => ['-1001'],
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_not_configured(): void
    {
        Http::fake();
        config()->set('paygrid.telegram_broadcast.bot_token', null);
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));

        $this->get(route('superadmin.page', 'broadcast-telegram'))->assertOk()->assertSee('Belum dikonfigurasi');
        $this->post(route('superadmin.broadcast-telegram.send'), [
            'message' => 'Halo',
            'chat_ids' => ['-1001'],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }
}
