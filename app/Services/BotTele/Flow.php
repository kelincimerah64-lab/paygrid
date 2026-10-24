<?php

namespace App\Services\BotTele;

use App\Models\BotAttendance;
use App\Models\BotTicket;

/** Shared plumbing for the bot flows. $u is always the UpdateNormalizer output. */
abstract class Flow
{
    public function __construct(
        protected TelegramClient $telegram,
        protected TicketCards $cards,
    ) {}

    protected function displayName(array $u): string
    {
        return trim(($u['first_name'] ?: '').' '.($u['last_name'] ?: ''));
    }

    /** Sheet cells were '' when empty; DB columns (bigint/datetime) need null instead. */
    protected function nullable(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    protected function isAssignedTo(BotTicket $ticket, array $u): bool
    {
        return (string) $ticket->assigned_user_id === (string) $u['user_id'];
    }

    protected function techChatId(): mixed
    {
        return config('bottele.tech_chat_id');
    }

    /**
     * Ids of the OPEN reminder cards. n8n stored them "|" or ","-separated; ids it once
     * glued together without a separator just parse as one bogus id, which Telegram ignores.
     *
     * @return list<int>
     */
    protected function reminderIds(BotTicket $ticket): array
    {
        return collect(preg_split('/[|,]/', (string) $ticket->open_reminder_message_ids))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /** "GS - Release Active Tech *": the technician no longer holds this ticket. */
    protected function releaseTechnician(mixed $userId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        BotAttendance::query()->where('user_id', $userId)->update(['active_ticket_id' => null, 'updated_at' => BotTime::nowString()]);
    }
}
