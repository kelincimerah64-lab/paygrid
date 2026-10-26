<?php

namespace Tests\Feature;

use App\Models\BotAttendance;
use App\Models\BotTicket;
use App\Services\BotTele\AutomationFlow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesBotTeleDatabase;
use Tests\TestCase;

class BotTeleAutomationTest extends TestCase
{
    use UsesBotTeleDatabase;

    private const TECH_CHAT = '-1004445566939';

    private const PIC_CHAT = '8254880804';

    private int $nextMessageId = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bottele.pic_escalation_chat_id' => self::PIC_CHAT]);
        Http::fake(['https://api.telegram.org/*' => fn (Request $request) => Http::response([
            'ok' => true,
            'result' => str_ends_with($request->url(), '/sendMessage') ? ['message_id' => ++$this->nextMessageId] : true,
        ])]);
        $this->at('09:00:00');
    }

    private function at(string $time): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 '.$time, 'Asia/Jakarta'));
    }

    private function runBot(string $command): void
    {
        $this->artisan('bottele:'.$command)->assertSuccessful();
    }

    private function sent(string $method): Collection
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/'.$method))
            ->map(fn (array $pair): array => $pair[0]->data())
            ->values();
    }

    private function openTicket(array $extra = []): BotTicket
    {
        return BotTicket::query()->create(array_merge([
            'ticket_id' => 'HGT-1', 'created_at' => '2026-10-23 09:00:00', 'source_chat_id' => -100777, 'source_chat_title' => 'Kingspay',
            'requester_user_id' => 501, 'requester_name' => 'Merchant', 'category' => 'Technical Issue', 'description' => 'Down',
            'status' => 'OPEN', 'cs_group_message_id' => 10, 'merchant_message_id' => 20,
        ], $extra));
    }

    private function technician(int $userId, array $extra = []): BotAttendance
    {
        return BotAttendance::query()->create(array_merge([
            'user_id' => $userId, 'chat_id' => $userId, 'name' => 'Tech '.$userId, 'status' => 'HADIR',
            'check_in_at' => '2026-10-23 08:00:00', 'expires_at' => '2026-10-23 16:00:00',
        ], $extra));
    }

    public function test_reminder_ladder_fires_at_ten_twenty_and_thirty_minutes_and_replaces_the_card(): void
    {
        $ticket = $this->openTicket();

        $this->at('09:09:59');
        $this->runBot('check-open-ticket-reminders');
        $this->assertSame(0, (int) $ticket->refresh()->open_reminder_count);

        $this->at('09:10:00');
        $this->runBot('check-open-ticket-reminders');
        $this->runBot('check-open-ticket-reminders');
        $ticket->refresh();
        $this->assertSame(1, $ticket->open_reminder_count);
        $this->assertSame('5001', $ticket->open_reminder_message_ids);
        $this->assertSame(5001, (int) $ticket->cs_group_message_id);
        $this->assertSame('2026-10-23 09:10:00', $ticket->last_open_reminder_at);
        $this->assertSame([10], $this->sent('deleteMessage')->pluck('message_id')->all());
        $first = $this->sent('sendMessage')->sole();
        $this->assertSame(self::TECH_CHAT, $first['chat_id']);
        $this->assertStringStartsWith("<b>REMINDER 1/3</b>\n<b>HGT-1</b>\nMerchant Group : Kingspay", $first['text']);
        $this->assertStringEndsWith("Status : 🔴\nWaiting Time : 10 Minutes\nTech On Shift : belum ada yang absen", $first['text']);
        $this->assertSame('take_HGT-1', $first['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->at('09:19:00');
        $this->runBot('check-open-ticket-reminders');
        $this->assertSame(1, $ticket->refresh()->open_reminder_count);

        $this->at('09:20:00');
        $this->runBot('check-open-ticket-reminders');
        $this->assertSame(2, $ticket->refresh()->open_reminder_count);
        $this->assertSame(5001, $this->sent('deleteMessage')->last()['message_id']);

        $this->at('09:29:00');
        $this->runBot('check-open-ticket-reminders');
        $this->assertSame(2, $ticket->refresh()->open_reminder_count);

        $this->at('09:30:00');
        $this->runBot('check-open-ticket-reminders');
        $this->assertSame(3, $ticket->refresh()->open_reminder_count);
        $this->assertStringStartsWith('<b>FINAL REMINDER 3/3</b>', $this->sent('sendMessage')->last()['text']);
        $this->assertCount(3, $this->sent('sendMessage'));
    }

    public function test_reminder_tags_technicians_on_shift_and_the_leader(): void
    {
        config(['bottele.leader_user_id' => '900', 'bottele.leader_name' => 'Gummy']);
        $this->openTicket();
        $this->technician(701);
        $this->technician(702, ['active_ticket_id' => 'HGT-OTHER']);
        // Checked in more than 8 hours ago, and one already marked off: neither is on shift.
        $this->technician(703, ['expires_at' => '2026-10-23 09:05:00']);
        $this->technician(704, ['status' => 'TIDAK_HADIR']);

        $this->at('09:10:00');
        $this->runBot('check-open-ticket-reminders');

        $this->assertStringEndsWith(
            "Tech On Shift : <a href=\"tg://user?id=701\">Tech 701</a>, <a href=\"tg://user?id=702\">Tech 702</a>\nLeader : <a href=\"tg://user?id=900\">Gummy</a>",
            $this->sent('sendMessage')->sole()['text'],
        );
    }

    public function test_ip_whitelist_reminder_shows_approver(): void
    {
        $this->openTicket(['category' => 'IP Whitelist / VPN', 'approval_by_user_id' => 801, 'approval_by_name' => 'Boss']);

        $this->at('09:10:00');
        $this->runBot('check-open-ticket-reminders');

        $this->assertStringContainsString("Reported By : <a href=\"tg://user?id=501\">Merchant</a>\nApproved By : <a href=\"tg://user?id=801\">Boss</a>\nIssue : Down", $this->sent('sendMessage')->sole()['text']);
    }

    public function test_auto_assign_waits_for_the_run_after_the_final_reminder_and_for_an_available_tech(): void
    {
        $ticket = $this->openTicket(['open_reminder_count' => 2]);
        $this->technician(702);

        // The FINAL reminder and the auto-assign never share a run.
        $this->at('09:30:00');
        $this->runBot('check-open-ticket-reminders');
        $this->assertSame('OPEN', $ticket->refresh()->status);
        $this->assertSame(3, $ticket->open_reminder_count);
        $finalCard = (int) $ticket->cs_group_message_id;

        BotAttendance::query()->where('user_id', 702)->update(['active_ticket_id' => 'HGT-BUSY']);
        $this->at('09:31:00');
        $this->runBot('check-open-ticket-reminders');
        $this->assertSame('OPEN', $ticket->refresh()->status);

        BotAttendance::query()->where('user_id', 702)->update(['active_ticket_id' => null]);
        $this->at('09:32:00');
        $this->runBot('check-open-ticket-reminders');

        $ticket->refresh();
        $this->assertSame('ASSIGNED', $ticket->status);
        $this->assertSame(702, (int) $ticket->assigned_user_id);
        $this->assertSame('Tech 702', $ticket->assigned_name);
        $this->assertSame('AUTO', $ticket->assignment_type);
        $this->assertSame('2026-10-23 09:32:00', $ticket->auto_assigned_at);
        $this->assertSame(0, $ticket->open_reminder_count);
        $this->assertNull($ticket->open_reminder_message_ids);
        $this->assertNull($ticket->last_open_reminder_at);

        $tech = BotAttendance::query()->where('user_id', 702)->sole();
        $this->assertSame('HGT-1', $tech->active_ticket_id);
        $this->assertSame('2026-10-23 09:32:00', $tech->last_auto_assigned_at);

        $private = $this->sent('sendMessage')->last();
        $this->assertSame(702, $private['chat_id']);
        $this->assertSame("<b>HGT-1</b>\nMerchant Group : Kingspay\nCategory : Technical Issue\nIssue : Down\nStatus : 🟡\nPIC : Tech 702\nAssignment : Auto", $private['text']);
        $this->assertSame('progress_HGT-1', $private['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame($this->nextMessageId, (int) $ticket->auto_assigned_message_id);

        $master = $this->sent('editMessageText')->sole();
        $this->assertSame($finalCard, $master['message_id']);
        $this->assertStringEndsWith("Assignment : Auto\nLast Update : Ticket telah di-auto assign ke Technical Support.", $master['text']);
        $this->assertSame([], $master['reply_markup']['inline_keyboard']);
    }

    public function test_random_pick_only_chooses_among_eligible_technicians(): void
    {
        $this->technician(701, ['expires_at' => '2026-10-23 08:59:00']);
        $this->technician(702, ['active_ticket_id' => 'HGT-BUSY']);
        $this->technician(703, ['status' => 'TIDAK_HADIR']);
        $this->technician(704);
        $this->technician(705);

        $offered = null;
        $flow = app(AutomationFlow::class);
        $flow->pickTechnicianWith(function (Collection $eligible) use (&$offered): BotAttendance {
            $offered = $eligible->pluck('user_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

            return $eligible->random();
        });
        $this->app->instance(AutomationFlow::class, $flow);

        $ticket = $this->openTicket(['open_reminder_count' => 3, 'created_at' => '2026-10-23 08:00:00']);
        $this->runBot('check-open-ticket-reminders');

        $this->assertSame([704, 705], $offered);
        $this->assertContains((int) $ticket->refresh()->assigned_user_id, [704, 705]);
    }

    public function test_real_random_pick_spreads_over_eligible_technicians(): void
    {
        $this->technician(704);
        $this->technician(705);
        $chosen = [];

        for ($i = 0; $i < 30; $i++) {
            BotTicket::query()->delete();
            BotAttendance::query()->update(['active_ticket_id' => null]);
            $this->openTicket(['ticket_id' => 'HGT-R'.$i, 'open_reminder_count' => 3, 'created_at' => '2026-10-23 08:00:00']);
            $this->runBot('check-open-ticket-reminders');
            $chosen[] = (int) BotTicket::findByTicketId('HGT-R'.$i)->assigned_user_id;
        }

        $this->assertSame([704, 705], collect($chosen)->unique()->sort()->values()->all());
    }

    public function test_escalation_after_ten_minutes_goes_to_the_pic_once(): void
    {
        $ticket = $this->openTicket([
            'status' => 'ASSIGNED', 'assignment_type' => 'AUTO', 'auto_assigned_at' => '2026-10-23 09:00:00',
            'assigned_user_id' => 702, 'assigned_name' => 'Tech 702', 'auto_assigned_message_id' => 77,
        ]);

        $this->at('09:09:59');
        $this->runBot('check-auto-assign-escalation');
        $this->assertNull($ticket->refresh()->escalated);

        $this->at('09:10:00');
        $this->runBot('check-auto-assign-escalation');
        $this->runBot('check-auto-assign-escalation');

        $ticket->refresh();
        $this->assertTrue($ticket->escalated);
        $this->assertSame('2026-10-23 09:10:00', $ticket->escalated_at);
        $this->assertSame('Ticket escalated to PIC after 30 minutes without Start Investigation.', $ticket->last_update);
        $this->assertSame('ASSIGNED', $ticket->status);
        $this->assertSame(702, (int) $ticket->assigned_user_id);

        $deleted = $this->sent('deleteMessage')->sole();
        $this->assertSame(702, $deleted['chat_id']);
        $this->assertSame(77, $deleted['message_id']);

        $toPic = $this->sent('sendMessage')->sole();
        $this->assertSame(self::PIC_CHAT, $toPic['chat_id']);
        $this->assertSame("<b>HGT-1</b>\nMerchant Group : Kingspay\nCategory : Technical Issue\nIssue : Down\nStatus : 🟠\nPIC : Tech 702\nWaiting Time : 10 Minutes\nNote : Investigation has not been started.", $toPic['text']);
        $this->assertSame('picprogress_HGT-1', $toPic['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $master = $this->sent('editMessageText')->sole();
        $this->assertSame(10, $master['message_id']);
        $this->assertStringContainsString("Status : 🟠\nPrevious PIC : Tech 702\nLast Update : Ticket dialihkan ke PIC karena investigasi belum dimulai.", $master['text']);
    }

    public function test_started_ticket_is_never_escalated(): void
    {
        $this->openTicket(['status' => 'ASSIGNED', 'assignment_type' => 'AUTO', 'auto_assigned_at' => '2026-10-23 08:00:00', 'started_at' => '2026-10-23 08:05:00']);

        $this->runBot('check-auto-assign-escalation');

        Http::assertNothingSent();
    }

    public function test_pic_expiry_after_ten_minutes_releases_the_original_tech(): void
    {
        $ticket = $this->openTicket([
            'status' => 'ASSIGNED', 'assignment_type' => 'AUTO', 'escalated' => true, 'escalated_at' => '2026-10-23 09:00:00',
            'assigned_user_id' => 702,
        ]);
        $this->technician(702, ['active_ticket_id' => 'HGT-1']);

        $this->at('09:09:00');
        $this->runBot('check-pic-expiry');
        $this->assertSame('ASSIGNED', $ticket->refresh()->status);

        $this->at('09:10:00');
        $this->runBot('check-pic-expiry');

        $ticket->refresh();
        $this->assertSame('EXPIRED', $ticket->status);
        $this->assertSame('2026-10-23 09:10:00', $ticket->completed_at);
        $this->assertSame('Ticket expired automatically after 30 minutes without action from PIC.', $ticket->last_update);
        $this->assertNull(BotAttendance::query()->where('user_id', 702)->value('active_ticket_id'));
        Http::assertNothingSent();
    }

    public function test_attendance_expires_after_eight_hours_and_notifies_once(): void
    {
        $this->technician(701, ['check_in_at' => '2026-10-23 01:00:00', 'expires_at' => '2026-10-23 09:00:01']);
        $this->technician(702, ['expires_at' => '2026-10-23 09:00:00']);

        $this->runBot('check-attendance-expiry');
        $this->runBot('check-attendance-expiry');

        $this->assertSame('HADIR', BotAttendance::query()->where('user_id', 701)->value('status'));
        $expired = BotAttendance::query()->where('user_id', 702)->sole();
        $this->assertSame('TIDAK_HADIR', $expired->status);
        $this->assertTrue($expired->expiry_notified);

        $notice = $this->sent('sendMessage')->sole();
        $this->assertSame(702, $notice['chat_id']);
        $this->assertStringStartsWith('⏰ <b>MASA KEHADIRAN BERAKHIR</b>', $notice['text']);
        $this->assertSame('HTML', $notice['parse_mode']);
    }

    public function test_commands_do_nothing_until_configured(): void
    {
        config(['bottele.bot_token' => '']);
        $this->openTicket(['created_at' => '2026-10-23 07:00:00']);

        foreach (['check-open-ticket-reminders', 'check-auto-assign-escalation', 'check-pic-expiry', 'check-attendance-expiry'] as $command) {
            $this->runBot($command);
        }

        $this->assertSame(0, (int) BotTicket::findByTicketId('HGT-1')->open_reminder_count);
        Http::assertNothingSent();
    }

    public function test_automation_since_skips_older_tickets(): void
    {
        config(['bottele.automation_since' => '2026-10-23 08:30:00']);
        $old = $this->openTicket(['created_at' => '2026-10-23 08:00:00']);

        $this->runBot('check-open-ticket-reminders');

        $this->assertSame(0, (int) $old->refresh()->open_reminder_count);
        Http::assertNothingSent();
    }
}
