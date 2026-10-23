<?php

namespace Tests\Feature;

use App\Models\TelegramBroadcastMessage;
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

    private function csPusat(): User
    {
        return User::factory()->create(['role' => 'cs_pusat', 'is_active' => true]);
    }

    public function test_cs_pusat_sees_page_and_menu(): void
    {
        $this->actingAs($this->csPusat());

        $this->get(route('center-support.broadcast'))
            ->assertOk()
            ->assertSee('Grup CS')
            ->assertSee('Grup Ops')
            ->assertSee('History Broadcast');
        $this->get(route('center-support.tickets'))->assertSee(route('center-support.broadcast'));
    }

    public function test_no_group_selected_sends_to_all_groups(): void
    {
        Http::fake(['https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        $this->actingAs($this->csPusat());

        $this->post(route('center-support.broadcast.send'), ['message' => 'Halo semua'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Broadcast Telegram - Grup CS: terkirim, Grup MA: terkirim, Grup Ops: terkirim.');

        Http::assertSentCount(3);
        $this->assertSame(3, TelegramBroadcastMessage::query()->where('status', 'sent')->where('telegram_message_id', 77)->count());
    }

    public function test_selected_groups_only_and_failures_are_recorded(): void
    {
        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 10]])
                ->push(['ok' => false, 'description' => 'Forbidden: bot was kicked'], 403),
        ]);
        $this->actingAs($this->csPusat());

        $this->post(route('center-support.broadcast.send'), [
            'message' => 'Maintenance *penting*',
            'chat_ids' => ['-1001', '-1003'],
        ])->assertSessionHas('status', 'Broadcast Telegram - Grup CS: terkirim, Grup Ops: gagal.');

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request['chat_id'] === '-1002');
        $this->assertDatabaseHas('telegram_broadcast_messages', ['chat_id' => '-1001', 'status' => 'sent', 'telegram_message_id' => 10]);
        $this->assertDatabaseHas('telegram_broadcast_messages', ['chat_id' => '-1003', 'status' => 'failed', 'telegram_message_id' => null]);
    }

    public function test_delete_removes_only_that_groups_message(): void
    {
        Http::fake(['https://api.telegram.org/bottest-token/deleteMessage' => Http::response(['ok' => true, 'result' => true])]);
        $user = $this->csPusat();
        $this->actingAs($user);
        $row = fn (string $chatId) => TelegramBroadcastMessage::query()->create([
            'batch_id' => 'b1', 'sent_by' => $user->id, 'message' => 'Halo', 'group_name' => 'G'.$chatId,
            'chat_id' => $chatId, 'telegram_message_id' => 5, 'status' => 'sent',
        ]);
        $target = $row('-1001');
        $other = $row('-1002');

        $this->delete(route('center-support.broadcast.destroy', $target))->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request['chat_id'] === '-1001' && $request['message_id'] === 5);
        Http::assertSentCount(1);
        $this->assertSame('deleted', $target->refresh()->status);
        $this->assertSame($user->id, $target->deleted_by);
        $this->assertSame('sent', $other->refresh()->status);
    }

    public function test_failed_telegram_delete_keeps_message_sent(): void
    {
        Http::fake(['https://api.telegram.org/bottest-token/deleteMessage' => Http::response(['ok' => false, 'description' => 'message can\'t be deleted'], 400)]);
        $user = $this->csPusat();
        $this->actingAs($user);
        $message = TelegramBroadcastMessage::query()->create([
            'batch_id' => 'b1', 'sent_by' => $user->id, 'message' => 'Halo', 'group_name' => 'Grup CS',
            'chat_id' => '-1001', 'telegram_message_id' => 5, 'status' => 'sent',
        ]);

        $this->delete(route('center-support.broadcast.destroy', $message))->assertSessionHasErrors('broadcast');

        $this->assertSame('sent', $message->refresh()->status);
    }

    public function test_unknown_chat_id_is_rejected(): void
    {
        Http::fake();
        $this->actingAs($this->csPusat());

        $this->post(route('center-support.broadcast.send'), ['message' => 'Halo', 'chat_ids' => ['-9999']])
            ->assertSessionHasErrors('chat_ids.0');

        Http::assertNothingSent();
    }

    public function test_superadmin_and_ma_cannot_use_broadcast(): void
    {
        Http::fake();

        foreach (['superadmin', 'ma'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('center-support.broadcast'))->assertForbidden();
            $this->post(route('center-support.broadcast.send'), ['message' => 'Halo'])->assertForbidden();
            $this->get(route('center-support.tickets'))->assertDontSee(route('center-support.broadcast'));
        }

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_not_configured(): void
    {
        Http::fake();
        config()->set('paygrid.telegram_broadcast.bot_token', null);
        $this->actingAs($this->csPusat());

        $this->get(route('center-support.broadcast'))->assertOk()->assertSee('Belum dikonfigurasi');
        $this->post(route('center-support.broadcast.send'), ['message' => 'Halo'])->assertStatus(422);

        Http::assertNothingSent();
    }
}
