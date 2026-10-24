<?php

namespace App\Services\BotTele;

use App\Models\BotAttendance;
use App\Models\BotTicket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ports of the four n8n Schedule Trigger workflows, each run every minute by
 * its own bottele:check-* command:
 *
 *   remind_tiket_mnt_v2     -> openTicketReminders()  reminders at 10/20/30 min, then random auto-assign
 *   Auto_Assign_Escalation  -> autoAssignEscalation() 10 min after auto-assign with no start: to the PIC
 *   PIC_Ticket_Expiry       -> picExpiry()            10 min after escalation with no start: EXPIRED
 *   absen_expired           -> attendanceExpiry()     8-hour check-in ran out: TIDAK_HADIR + notice
 *
 * "Minutes" are whole minutes floored, like the n8n Math.floor(... / 60000).
 */
class AutomationFlow extends Flow
{
    public const REMINDER_LADDER = [1 => 10, 2 => 20, 3 => 30];

    public const ESCALATE_AFTER_MINUTES = 10;

    public const EXPIRE_AFTER_MINUTES = 10;

    /** @var \Closure(Collection<int, BotAttendance>): BotAttendance */
    private \Closure $pickTechnician;

    public function __construct(TelegramClient $telegram, TicketCards $cards)
    {
        parent::__construct($telegram, $cards);

        // "Code - Pick Random Available Tech": a uniformly random eligible tech, not round-robin.
        $this->pickTechnician = fn (Collection $eligible): BotAttendance => $eligible->random();
    }

    /** @return array{reminded: int, assigned: int} */
    public function openTicketReminders(): array
    {
        $now = BotTime::now();
        $counts = ['reminded' => 0, 'assigned' => 0];

        $tickets = $this->ticketsInScope()
            ->where('status', 'OPEN')
            ->orderBy('id')
            ->get()
            ->filter(fn (BotTicket $t): bool => (string) $t->assigned_user_id === '');

        foreach ($tickets as $ticket) {
            // "Code - Check OPEN Reminder": both decisions come from the count read at the start of the run,
            // so the FINAL reminder and the auto-assign never happen in the same minute.
            $waiting = $this->minutesSince($ticket->created_at, $now);
            $count = (int) $ticket->open_reminder_count;
            $next = $count + 1;

            if (isset(self::REMINDER_LADDER[$next]) && $waiting >= self::REMINDER_LADDER[$next]) {
                $this->sendReminder($ticket, $next, $waiting, $now);
                $counts['reminded']++;
            } elseif ($count >= 3 && $waiting >= self::REMINDER_LADDER[3] && $this->autoAssign($ticket, $now)) {
                $counts['assigned']++;
            }
        }

        return $counts;
    }

    public function autoAssignEscalation(): int
    {
        $now = BotTime::now();
        $escalated = 0;

        $tickets = $this->ticketsInScope()->where('status', 'ASSIGNED')->where('assignment_type', 'AUTO')->get();

        foreach ($tickets as $ticket) {
            $autoAssignedAt = BotTime::parse($ticket->auto_assigned_at);
            $minutes = $this->minutesSince($ticket->auto_assigned_at, $now);
            $shouldEscalate = $autoAssignedAt
                && trim((string) $ticket->started_at) === ''
                && $minutes >= self::ESCALATE_AFTER_MINUTES
                && $ticket->escalated !== true;

            if (! $shouldEscalate) {
                continue;
            }

            // "TG - Delete Random Tech Card": a private chat's id equals the tech's user id.
            $this->telegram->deleteMessage($ticket->assigned_user_id, $ticket->auto_assigned_message_id);
            $this->telegram->sendMessage(config('bottele.pic_escalation_chat_id'), $this->cards->escalationToPic($ticket, $minutes), TicketCards::picStartKeyboard($ticket->ticket_id));

            $at = $now->format(BotTime::FORMAT);
            $ticket->update([
                'escalated' => true,
                'escalated_at' => $at,
                'updated_at' => $at,
                // Kept verbatim from n8n although the real wait is 10 minutes after auto-assign.
                'last_update' => 'Ticket escalated to PIC after 30 minutes without Start Investigation.',
            ]);

            $this->telegram->editMessageText($this->techChatId(), $ticket->cs_group_message_id, $this->cards->escalatedMaster($ticket), []);
            $escalated++;
        }

        return $escalated;
    }

    public function picExpiry(): int
    {
        $now = BotTime::now();
        $expired = 0;

        foreach ($this->ticketsInScope()->where('escalated', true)->get() as $ticket) {
            $shouldExpire = strtoupper((string) $ticket->status) === 'ASSIGNED'
                && strtoupper((string) $ticket->assignment_type) === 'AUTO'
                && trim((string) $ticket->started_at) === ''
                && BotTime::parse($ticket->escalated_at)
                && $this->minutesSince($ticket->escalated_at, $now) >= self::EXPIRE_AFTER_MINUTES;

            if (! $shouldExpire) {
                continue;
            }

            $at = $now->format(BotTime::FORMAT);
            $ticket->update([
                'status' => 'EXPIRED',
                'completed_at' => $at,
                'updated_at' => $at,
                // Kept verbatim from n8n although the real wait is 10 minutes after escalation.
                'last_update' => 'Ticket expired automatically after 30 minutes without action from PIC.',
            ]);
            // assigned_user_id is still the original tech: the PIC only replaces it by clicking picprogress_.
            $this->releaseTechnician($ticket->assigned_user_id);
            $expired++;
        }

        return $expired;
    }

    public function attendanceExpiry(): int
    {
        $now = BotTime::now();
        $notified = 0;

        foreach (BotAttendance::query()->where('status', 'HADIR')->get() as $attendance) {
            $expiresAt = BotTime::parse($attendance->expires_at);
            if (! $expiresAt || $now->lessThan($expiresAt) || $attendance->expiry_notified) {
                continue;
            }

            $this->telegram->sendMessage($attendance->chat_id, TicketCards::ATTENDANCE_EXPIRED);
            $attendance->update(['status' => 'TIDAK_HADIR', 'expiry_notified' => true, 'updated_at' => $now->format(BotTime::FORMAT)]);
            $notified++;
        }

        return $notified;
    }

    /** Test seam for the random pick. */
    public function pickTechnicianWith(\Closure $picker): void
    {
        $this->pickTechnician = $picker;
    }

    /** "Prepare Delete Previous Card" -> delete -> "HTTP Request" -> "Append Reminder Message ID" -> update row. */
    private function sendReminder(BotTicket $ticket, int $number, int $waiting, CarbonImmutable $now): void
    {
        // cs_group_message_id is the single pointer to the ticket's live card in the tech group.
        $this->telegram->deleteMessage($this->techChatId(), $ticket->cs_group_message_id);

        $messageId = $this->telegram->sendMessage($this->techChatId(), $this->cards->openReminder($ticket, $number, $waiting), TicketCards::takeKeyboard($ticket->ticket_id));

        $ticket->update([
            'open_reminder_message_ids' => $messageId !== null ? (string) $messageId : null,
            'open_reminder_count' => $number,
            'last_open_reminder_at' => $now->format(BotTime::FORMAT),
            'cs_group_message_id' => $messageId,
        ]);
    }

    /** "GS - Find HADIR Tech" -> "Code - Pick Random Available Tech" -> "GS - Auto Assign Ticket" and its two branches. */
    private function autoAssign(BotTicket $ticket, CarbonImmutable $now): bool
    {
        $eligible = BotAttendance::query()->where('status', 'HADIR')->get()->filter(function (BotAttendance $tech) use ($now): bool {
            $expiresAt = BotTime::parse($tech->expires_at);

            return $expiresAt && $expiresAt->greaterThan($now) && trim((string) $tech->active_ticket_id) === '';
        })->values();

        if ($eligible->isEmpty()) {
            return false;
        }

        $tech = ($this->pickTechnician)($eligible);
        $at = $now->format(BotTime::FORMAT);

        // Conditional so a manual Take in the same second still wins cleanly.
        $taken = BotTicket::query()->where('ticket_id', $ticket->ticket_id)->where('status', 'OPEN')->whereNull('assigned_user_id')->update([
            'status' => 'ASSIGNED',
            'assigned_user_id' => $tech->user_id,
            'assigned_name' => $tech->name,
            'assigned_at' => $at,
            'assignment_type' => 'AUTO',
            'auto_assigned_at' => $at,
            'updated_at' => $at,
        ]);
        if (! $taken) {
            return false;
        }

        $tech->update(['active_ticket_id' => $ticket->ticket_id, 'last_auto_assigned_at' => $at, 'updated_at' => $at]);
        $reminderIds = $this->reminderIds($ticket);
        $ticket->refresh();

        $privateId = $this->telegram->sendMessage($tech->chat_id, $this->cards->autoAssignedPrivate($ticket), TicketCards::startKeyboard($ticket->ticket_id));
        $ticket->update(['auto_assigned_message_id' => $privateId, 'updated_at' => $at]);

        // n8n deleted every reminder id first, which included the card cs_group_message_id points at,
        // so its "Edit Master" call always failed. The master card is edited and kept instead.
        $this->telegram->editMessageText($this->techChatId(), $ticket->cs_group_message_id, $this->cards->autoAssignedMaster($ticket), []);
        foreach ($reminderIds as $messageId) {
            if ($messageId !== (int) $ticket->cs_group_message_id) {
                $this->telegram->deleteMessage($this->techChatId(), $messageId);
            }
        }

        $ticket->update(['open_reminder_message_ids' => null, 'open_reminder_count' => 0, 'last_open_reminder_at' => null, 'updated_at' => $at]);

        return true;
    }

    /** BOTTELE_AUTOMATION_SINCE, when set, keeps every scheduled job away from older (imported) tickets. */
    private function ticketsInScope(): Builder
    {
        return BotTicket::query()->when(
            BotTime::parse(config('bottele.automation_since')),
            fn (Builder $q, CarbonImmutable $since) => $q->where('created_at', '>=', $since->format(BotTime::FORMAT)),
        );
    }

    private function minutesSince(mixed $timestamp, CarbonImmutable $now): int
    {
        $from = BotTime::parse($timestamp);

        return $from ? intdiv($now->getTimestamp() - $from->getTimestamp(), 60) : 0;
    }
}
