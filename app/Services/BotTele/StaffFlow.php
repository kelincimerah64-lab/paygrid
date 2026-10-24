<?php

namespace App\Services\BotTele;

use App\Models\BotAttendance;
use App\Models\BotTicket;

/** Technician utilities: private-chat "absen" check-in and the admin-group /checkticket listing. */
class StaffFlow extends Flow
{
    /** "SET - Attendance Data" -> "IF - Attendance Exists?" -> update/create -> confirmation card. */
    public function checkIn(array $u): void
    {
        $now = BotTime::now();
        $checkInAt = $now->format(BotTime::FORMAT);
        $expiresAt = $now->addHours((int) config('bottele.attendance_hours', 8))->format(BotTime::FORMAT);
        $name = $this->displayName($u);

        // active_ticket_id and last_auto_assigned_at survive a re-check-in (n8n copied them over).
        $attendance = BotAttendance::query()->firstOrNew(['user_id' => $u['user_id']]);
        $attendance->fill([
            'chat_id' => $this->nullable($u['chat_id']),
            'username' => $this->nullable($u['username']),
            'name' => $this->nullable($name),
            'status' => 'HADIR',
            'check_in_at' => $checkInAt,
            'expires_at' => $expiresAt,
            'expiry_notified' => false,
            'updated_at' => $checkInAt,
        ])->save();

        $this->telegram->sendMessage($u['chat_id'], $this->cards->attendance($name, $checkInAt, $expiresAt));
    }

    /** "GS - Find OPEN Tickets For Check" -> "Code - Filter Untaken Tickets" -> one card per ticket. */
    public function checkTickets(): void
    {
        $adminChatId = config('bottele.admin_chat_id');
        $open = BotTicket::query()->where('status', 'OPEN')->orderBy('id')->get();

        if ($open->isEmpty()) {
            $this->telegram->sendMessage($adminChatId, TicketCards::NO_OPEN_TICKETS);

            return;
        }

        // Like n8n: if every OPEN ticket is already assigned, nothing is sent.
        $now = BotTime::now();
        foreach ($open->filter(fn (BotTicket $t): bool => (string) $t->assigned_user_id === '') as $ticket) {
            $created = BotTime::parse($ticket->created_at);
            $waitingMinutes = $created ? intdiv($now->getTimestamp() - $created->getTimestamp(), 60) : 0;

            $this->telegram->sendMessage($adminChatId, $this->cards->checkTicket($ticket, $waitingMinutes), TicketCards::takeKeyboard($ticket->ticket_id));
        }
    }
}
