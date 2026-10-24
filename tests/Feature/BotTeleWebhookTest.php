<?php

namespace Tests\Feature;

use App\Models\BotAttendance;
use App\Models\BotSession;
use App\Models\BotTicket;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class BotTeleWebhookTest extends TestCase
{
    use UsesBotTeleDatabase;

    private const MERCHANT_CHAT = -1003933837716;

    private const TECH_CHAT = '-1004445566939';

    private const APPROVAL_CHAT = '-1004436542388';

    private const MERCHANT = 501;

    private const TECH = 701;

    private int $nextMessageId = 1000;

    private int $nextUpdateMessageId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Every Telegram call succeeds; sendMessage hands out increasing message ids.
        Http::fake(['https://api.telegram.org/*' => fn (Request $request) => Http::response([
            'ok' => true,
            'result' => str_ends_with($request->url(), '/sendMessage') ? ['message_id' => ++$this->nextMessageId] : true,
        ])]);
        $this->at('2026-10-23 09:00:00');
    }

    private function at(string $jakartaTime): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($jakartaTime, 'Asia/Jakarta'));
    }

    private function webhook(array $update): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'hook-secret')
            ->postJson('/api/bottele/webhook', $update)
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    private function message(string $text, int $userId = self::MERCHANT, int|string $chatId = self::MERCHANT_CHAT, string $chatType = 'supergroup', array $extra = []): int
    {
        $messageId = $this->nextUpdateMessageId++;
        $this->webhook(['message' => array_merge([
            'message_id' => $messageId,
            'chat' => ['id' => $chatId, 'type' => $chatType, 'title' => $chatType === 'private' ? null : 'Kingspay Group'],
            'from' => ['id' => $userId, 'first_name' => $userId === self::MERCHANT ? 'Merchant' : 'Tech', 'last_name' => 'User', 'username' => 'user'.$userId],
            'text' => $text,
        ], $extra)]);

        return $messageId;
    }

    private function click(string $data, int $userId, int|string $chatId, int $messageId): void
    {
        $this->webhook(['callback_query' => [
            'id' => 'cb'.$messageId,
            'data' => $data,
            'from' => ['id' => $userId, 'first_name' => $userId === self::MERCHANT ? 'Merchant' : 'Tech', 'last_name' => 'User'],
            'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Group']],
        ]]);
    }

    /** @return Collection<int, array> Telegram payloads sent to $method. */
    private function sent(string $method)
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/'.$method))
            ->map(fn (array $pair): array => $pair[0]->data())
            ->values();
    }

    /** Runs /support -> category -> description and returns the created ticket. */
    private function openTicket(string $callback = 'ticket_api', string $description = 'Callback gagal'): BotTicket
    {
        $this->message('/support');
        $menu = $this->sent('sendMessage')->last();
        $this->assertSame(TicketCardsKeyboardFixture::MENU, $menu['reply_markup']['inline_keyboard']);
        $this->click($callback, self::MERCHANT, self::MERCHANT_CHAT, $this->nextMessageId);
        $this->message($description);

        return BotTicket::query()->latest('id')->firstOrFail();
    }

    public function test_webhook_requires_the_configured_secret_token(): void
    {
        $this->postJson('/api/bottele/webhook', ['message' => []])->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')->postJson('/api/bottele/webhook', ['message' => []])->assertForbidden();

        config(['bottele.webhook_secret' => '']);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', '')->postJson('/api/bottele/webhook', ['message' => []])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_support_command_sends_menu_and_stores_cleanup_session(): void
    {
        $commandId = $this->message('/support');

        $menu = $this->sent('sendMessage')->sole();
        $this->assertSame(self::MERCHANT_CHAT, $menu['chat_id']);
        $this->assertArrayNotHasKey('parse_mode', $menu);
        $this->assertStringStartsWith('Bot Support', $menu['text']);

        $session = BotSession::for(self::MERCHANT, self::MERCHANT_CHAT);
        $this->assertSame('SUPPORT_MENU', $session->state);
        $this->assertSame($commandId, (int) $session->support_command_message_id);
        $this->assertSame($this->nextMessageId, (int) $session->support_menu_message_id);
    }

    public static function categories(): array
    {
        return [
            'merchant details' => ['ticket_dashboard', 'Merchant Details | User Role', 'OPEN'],
            'technical issue' => ['ticket_api', 'Technical Issue', 'OPEN'],
            'others' => ['ticket_other', 'Others', 'OPEN'],
            'ip whitelist' => ['ticket_whitelist', 'IP Whitelist / VPN', 'WAITING_APPROVAL'],
        ];
    }

    #[DataProvider('categories')]
    public function test_each_category_round_trip_creates_a_ticket(string $callback, string $category, string $status): void
    {
        $this->message('/support');
        $menuId = $this->nextMessageId;
        $this->click($callback, self::MERCHANT, self::MERCHANT_CHAT, $menuId);

        $lock = $this->sent('editMessageReplyMarkup')->sole();
        $this->assertSame($menuId, $lock['message_id']);
        $this->assertSame([], $lock['reply_markup']['inline_keyboard']);
        $this->assertSame($category, BotSession::for(self::MERCHANT, self::MERCHANT_CHAT)->category);

        $this->message('Butuh bantuan <segera> & cepat');

        $ticket = BotTicket::query()->sole();
        $this->assertMatchesRegularExpression('/^HGT-20261023-\d{18}$/', $ticket->ticket_id);
        $this->assertSame($category, $ticket->category);
        $this->assertSame($status, $ticket->status);
        $this->assertSame('Butuh bantuan <segera> & cepat', $ticket->description);
        $this->assertSame('Merchant User', $ticket->requester_name);
        $this->assertSame('2026-10-23 09:00:00', $ticket->created_at);
        $this->assertNull(BotSession::for(self::MERCHANT, self::MERCHANT_CHAT));
        $this->assertCount(4, $this->sent('deleteMessage'));

        // sendMessage order: menu, category prompt, merchant confirmation, group push.
        $confirm = $this->sent('sendMessage')[2];
        $this->assertSame(self::MERCHANT_CHAT, $confirm['chat_id']);
        $this->assertSame('HTML', $confirm['parse_mode']);
        $this->assertStringContainsString('Issue : Butuh bantuan &lt;segera&gt; &amp; cepat', $confirm['text']);
        $this->assertStringContainsString('Reported By : <a href="tg://user?id='.self::MERCHANT.'">Merchant User</a>', $confirm['text']);
        $this->assertStringEndsWith($status === 'WAITING_APPROVAL' ? "Status : 🟡\nApproval : Waiting for Approval" : 'Status : 🔴', $confirm['text']);
        $this->assertSame($this->nextMessageId - 1, (int) $ticket->merchant_message_id);

        $push = $this->sent('sendMessage')->last();
        if ($status === 'WAITING_APPROVAL') {
            $this->assertSame(self::APPROVAL_CHAT, $push['chat_id']);
            $this->assertSame('ipapprove_'.$ticket->ticket_id, $push['reply_markup']['inline_keyboard'][0][0]['callback_data']);
            $this->assertSame('WAITING_APPROVAL', $ticket->approval_status);
            $this->assertSame((int) $ticket->approval_group_message_id, $this->nextMessageId);
        } else {
            $this->assertSame(self::TECH_CHAT, $push['chat_id']);
            $this->assertSame('take_'.$ticket->ticket_id, $push['reply_markup']['inline_keyboard'][0][0]['callback_data']);
            $this->assertNull($ticket->approval_status);
            $this->assertSame((int) $ticket->cs_group_message_id, $this->nextMessageId);
        }
    }

    public function test_create_user_rejects_bad_format_then_creates_ticket(): void
    {
        $this->message('/support');
        $this->click('ticket_create_user', self::MERCHANT, self::MERCHANT_CHAT, $this->nextMessageId);
        $this->assertStringStartsWith('CREATE USER', $this->sent('sendMessage')->last()['text']);

        $this->message("Kingspay H+1\nKingspay\nadmin@kingspay.com");

        $this->assertSame(0, BotTicket::query()->count());
        $this->assertStringStartsWith('Format Create User tidak valid: Nama Merchant; Nama Grup Merchant; User Email; Role; Minimal 4 baris', $this->sent('sendMessage')->last()['text']);
        $this->assertSame('WAITING_CREATE_USER_DETAILS', BotSession::for(self::MERCHANT, self::MERCHANT_CHAT)->state);

        $this->message("Kingspay H+1\nKingspay\nadmin@kingspay.com, finance@kingspay.com\nMaster, Finance");

        $ticket = BotTicket::query()->sole();
        $this->assertSame('Create User', $ticket->category);
        $this->assertSame('OPEN', $ticket->status);
        $this->assertSame("Nama Merchant : Kingspay H+1\nNama Grup Merchant : Kingspay\nUser Email : admin@kingspay.com, finance@kingspay.com\nRole : Master, Finance", $ticket->description);
    }

    public function test_screenshot_caption_becomes_description_and_photo_is_forwarded(): void
    {
        $this->message('/support');
        $this->click('ticket_api', self::MERCHANT, self::MERCHANT_CHAT, $this->nextMessageId);
        $this->message('', extra: ['text' => null, 'caption' => 'Error 500', 'photo' => [['file_id' => 'small'], ['file_id' => 'big']]]);

        $ticket = BotTicket::query()->sole();
        $this->assertTrue($ticket->has_attachment);
        $this->assertSame('big', $ticket->attachment_file_id);
        $this->assertSame('Error 500', $ticket->description);

        $photo = $this->sent('sendPhoto')->sole();
        $this->assertSame(self::TECH_CHAT, $photo['chat_id']);
        $this->assertSame('big', $photo['photo']);
        $this->assertSame($ticket->ticket_id."\nCategory : Technical Issue", $photo['caption']);
    }

    public function test_take_progress_resolve_records_sla_in_seconds_and_releases_technician(): void
    {
        $ticket = $this->openTicket();
        $techCard = (int) $ticket->cs_group_message_id;
        BotAttendance::query()->create(['user_id' => self::TECH, 'status' => 'HADIR', 'active_ticket_id' => $ticket->ticket_id]);

        $this->at('2026-10-23 09:05:00');
        $this->click('take_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $techCard);
        $ticket->refresh();
        $this->assertSame('ASSIGNED', $ticket->status);
        $this->assertSame(self::TECH, (int) $ticket->assigned_user_id);
        $this->assertSame('Tech User', $ticket->assigned_name);
        $this->assertSame('progress_'.$ticket->ticket_id, $this->sent('editMessageText')->first()['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        // A second click on Take only reports who has it.
        $this->click('take_'.$ticket->ticket_id, 999, self::TECH_CHAT, $techCard);
        $this->assertSame('Ticket '.$ticket->ticket_id.' sudah diambil oleh Tech User.', $this->sent('sendMessage')->last()['text']);
        $this->assertSame(self::TECH, (int) $ticket->refresh()->assigned_user_id);

        // Only the assigned technician can start.
        $this->click('progress_'.$ticket->ticket_id, 999, self::TECH_CHAT, $techCard);
        $this->assertSame('ASSIGNED', $ticket->refresh()->status);

        $this->at('2026-10-23 09:10:00');
        $this->click('progress_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $techCard);
        $ticket->refresh();
        $this->assertSame('ON_PROGRESS', $ticket->status);
        $this->assertSame('2026-10-23 09:10:00', $ticket->started_at);
        $this->assertSame('2026-10-23 09:10:00', $ticket->assigned_at);

        $this->at('2026-10-23 10:00:00');
        $this->click('resolve_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $techCard);
        $ticket->refresh();
        $this->assertSame('RESOLVED', $ticket->status);
        $this->assertSame('2026-10-23 10:00:00', $ticket->completed_at);
        // assigned_at was rewritten on Start Investigation (n8n behaviour), so pickup runs to 09:10 and lag is 0.
        $this->assertSame(600, $ticket->pickup_minutes);
        $this->assertSame(0, $ticket->investigation_lag_minutes);
        $this->assertSame(3000, $ticket->handling_minutes);
        $this->assertSame(3600, $ticket->total_resolution_minutes);
        $this->assertNull(BotAttendance::query()->where('user_id', self::TECH)->value('active_ticket_id'));

        $resolvedCard = $this->sent('editMessageText')->last();
        $this->assertSame(self::MERCHANT_CHAT, $resolvedCard['chat_id']);
        $this->assertStringContainsString('Status : 🟢', $resolvedCard['text']);
    }

    public function test_take_deletes_other_open_reminder_cards(): void
    {
        $ticket = $this->openTicket();
        $ticket->update(['open_reminder_message_ids' => '301|302,303']);

        $this->click('take_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, 302);

        $deleted = $this->sent('deleteMessage')->filter(fn (array $p): bool => $p['chat_id'] === self::TECH_CHAT)->pluck('message_id')->all();
        $this->assertSame([301, 303], $deleted);
    }

    public function test_send_update_saves_last_update_and_edits_merchant_card_in_merchant_chat(): void
    {
        $ticket = $this->openTicket();
        $card = (int) $ticket->cs_group_message_id;
        $this->click('take_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $card);
        $this->click('progress_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $card);

        $this->click('update_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $card);
        $this->assertSame('WAITING_CS_UPDATE', BotSession::for(self::TECH, self::TECH_CHAT)->state);
        $this->assertStringContainsString('Silakan ketik update', $this->sent('sendMessage')->last()['text']);

        $this->message('Sedang cek upstream', self::TECH, self::TECH_CHAT);

        $ticket->refresh();
        $this->assertSame('Sedang cek upstream', $ticket->last_update);
        $this->assertSame('ON_PROGRESS', $ticket->status);
        $this->assertNull(BotSession::for(self::TECH, self::TECH_CHAT));

        $merchantEdit = $this->sent('editMessageText')->last(fn (array $p): bool => $p['message_id'] === (int) $ticket->merchant_message_id);
        $this->assertSame(self::MERCHANT_CHAT, $merchantEdit['chat_id']);
        $this->assertStringEndsWith('Last Update : Sedang cek upstream', $merchantEdit['text']);
    }

    public function test_failed_flow_stores_reason_and_numeric_total(): void
    {
        $ticket = $this->openTicket();
        $card = (int) $ticket->cs_group_message_id;
        $this->click('take_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $card);
        $this->click('progress_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $card);

        $this->click('failed_'.$ticket->ticket_id, self::TECH, self::TECH_CHAT, $card);
        $this->assertSame('WAITING_FAILED_REASON', BotSession::for(self::TECH, self::TECH_CHAT)->state);

        $this->at('2026-10-23 11:00:00');
        $this->message('Butuh tindakan provider', self::TECH, self::TECH_CHAT);

        $ticket->refresh();
        $this->assertSame('FAILED', $ticket->status);
        $this->assertSame('Butuh tindakan provider', $ticket->last_update);
        $this->assertSame(7200, $ticket->total_resolution_minutes);
        $this->assertNull(BotSession::for(self::TECH, self::TECH_CHAT));
        $this->assertStringContainsString('Reason : Butuh tindakan provider', $this->sent('editMessageText')->last()['text']);
    }

    public function test_ip_whitelist_approval_moves_ticket_to_the_tech_queue(): void
    {
        $ticket = $this->openTicket('ticket_whitelist', 'Whitelist 1.2.3.4');
        $approvalCard = (int) $ticket->approval_group_message_id;

        $this->click('ipapprove_'.$ticket->ticket_id, 801, self::APPROVAL_CHAT, $approvalCard);

        $ticket->refresh();
        $this->assertSame('OPEN', $ticket->status);
        $this->assertSame('APPROVED', $ticket->approval_status);
        $this->assertSame(801, (int) $ticket->approval_by_user_id);
        $push = $this->sent('sendMessage')->last();
        $this->assertSame(self::TECH_CHAT, $push['chat_id']);
        $this->assertSame('take_'.$ticket->ticket_id, $push['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame($this->nextMessageId, (int) $ticket->cs_group_message_id);

        // Already decided: a second approve does nothing.
        $calls = count(Http::recorded());
        $this->click('ipapprove_'.$ticket->ticket_id, 801, self::APPROVAL_CHAT, $approvalCard);
        $this->assertCount($calls, Http::recorded());
    }

    public function test_ip_whitelist_rejection_asks_for_note_then_rejects(): void
    {
        $ticket = $this->openTicket('ticket_whitelist', 'Whitelist 1.2.3.4');
        $approvalCard = (int) $ticket->approval_group_message_id;

        $this->click('ipreject_'.$ticket->ticket_id, 801, self::APPROVAL_CHAT, $approvalCard);
        $this->assertSame('WAITING_IP_REJECTION_NOTE', BotSession::for(801, self::APPROVAL_CHAT)->state);
        $this->assertSame('<b>'.$ticket->ticket_id."</b>\nPlease type the rejection reason.", $this->sent('sendMessage')->last()['text']);

        $this->message('IP bukan static', 801, self::APPROVAL_CHAT);

        $ticket->refresh();
        $this->assertSame('REJECTED', $ticket->status);
        $this->assertSame('REJECTED', $ticket->approval_status);
        $this->assertSame('IP bukan static', $ticket->approval_note);
        $this->assertNull(BotSession::for(801, self::APPROVAL_CHAT));
        $approvalEdit = $this->sent('editMessageText')->firstWhere('message_id', $approvalCard);
        $this->assertStringContainsString('Rejection Note : IP bukan static', $approvalEdit['text']);
        $this->assertArrayNotHasKey('reply_markup', $approvalEdit);
    }

    public function test_pic_can_take_over_an_escalated_auto_assigned_ticket(): void
    {
        $ticket = BotTicket::query()->create([
            'ticket_id' => 'HGT-20261023-1', 'created_at' => '2026-10-23 08:00:00', 'source_chat_id' => self::MERCHANT_CHAT,
            'category' => 'Technical Issue', 'description' => 'Down', 'status' => 'ASSIGNED', 'assigned_user_id' => 111,
            'assigned_name' => 'Auto Tech', 'assignment_type' => 'AUTO', 'escalated' => true, 'cs_group_message_id' => 50, 'merchant_message_id' => 40,
        ]);
        BotAttendance::query()->create(['user_id' => 111, 'active_ticket_id' => $ticket->ticket_id]);

        $this->click('picprogress_'.$ticket->ticket_id, 222, self::TECH_CHAT, 60);

        $ticket->refresh();
        $this->assertSame('ON_PROGRESS', $ticket->status);
        $this->assertSame(222, (int) $ticket->assigned_user_id);
        $this->assertSame('Investigation started by PIC.', $ticket->last_update);
        $this->assertSame('2026-10-23 09:00:00', $ticket->started_at);
        $this->assertNull(BotAttendance::query()->where('user_id', 111)->value('active_ticket_id'));
        $this->assertCount(3, $this->sent('editMessageText'));
    }

    public function test_pic_takeover_is_refused_when_not_escalated(): void
    {
        BotTicket::query()->create(['ticket_id' => 'HGT-20261023-2', 'status' => 'ASSIGNED', 'assigned_user_id' => 111, 'assignment_type' => 'AUTO', 'escalated' => false]);

        $this->click('picprogress_HGT-20261023-2', 222, self::TECH_CHAT, 60);

        $this->assertSame(111, (int) BotTicket::findByTicketId('HGT-20261023-2')->assigned_user_id);
        Http::assertNothingSent();
    }

    public function test_absen_checks_in_for_eight_hours_and_keeps_active_ticket(): void
    {
        BotAttendance::query()->create(['user_id' => self::TECH, 'status' => 'TIDAK_HADIR', 'active_ticket_id' => 'HGT-1', 'expiry_notified' => true]);

        $this->message('absen', self::TECH, self::TECH, 'private');

        $attendance = BotAttendance::query()->where('user_id', self::TECH)->sole();
        $this->assertSame('HADIR', $attendance->status);
        $this->assertSame('2026-10-23 09:00:00', $attendance->check_in_at);
        $this->assertSame('2026-10-23 17:00:00', $attendance->expires_at);
        $this->assertSame('HGT-1', $attendance->active_ticket_id);
        $this->assertFalse($attendance->expiry_notified);
        $this->assertStringContainsString('Aktif Sampai : 2026-10-23 17:00:00', $this->sent('sendMessage')->sole()['text']);
    }

    public function test_checkticket_lists_only_untaken_open_tickets(): void
    {
        $this->message('/checkticket', self::TECH, self::TECH_CHAT);
        $this->assertSame('Tidak ada ticket yang menunggu untuk di-TAKE.', $this->sent('sendMessage')->sole()['text']);

        BotTicket::query()->create(['ticket_id' => 'HGT-A', 'status' => 'OPEN', 'created_at' => '2026-10-23 08:15:00', 'category' => 'Others']);
        BotTicket::query()->create(['ticket_id' => 'HGT-B', 'status' => 'OPEN', 'assigned_user_id' => 5]);
        BotTicket::query()->create(['ticket_id' => 'HGT-C', 'status' => 'RESOLVED']);

        $this->message('/checkticket@MerchantSupportBot', self::TECH, self::TECH_CHAT);

        $cards = $this->sent('sendMessage')->slice(1)->values();
        $this->assertCount(1, $cards);
        $this->assertStringContainsString('<b>HGT-A</b>', $cards[0]['text']);
        $this->assertStringContainsString('Waiting Time : 45 menit', $cards[0]['text']);
        $this->assertSame('take_HGT-A', $cards[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function test_checkticket_outside_admin_chat_is_ignored(): void
    {
        $this->message('/checkticket');

        Http::assertNothingSent();
    }
}

/** The /support keyboard, spelled out so a template drift fails loudly. */
final class TicketCardsKeyboardFixture
{
    public const MENU = [
        [['text' => 'Merchant Details / User Role', 'callback_data' => 'ticket_dashboard'], ['text' => 'IP Whitelist / VPN', 'callback_data' => 'ticket_whitelist']],
        [['text' => 'Technical Issue', 'callback_data' => 'ticket_api'], ['text' => 'Others', 'callback_data' => 'ticket_other']],
        [['text' => 'Create User', 'callback_data' => 'ticket_create_user']],
    ];
}
