<?php

namespace App\Jobs;

use App\Models\MerchantTicket;
use App\Services\WhatsApp\WhatsAppNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Keeps exactly one "live card" per ticket in the WA group: 'created' posts
 * it, 'reminder' deletes the previous card and posts a fresh one (so the
 * group never accumulates stale reminders), 'approved' edits the card in the
 * approval group done and posts a brand-new card in the handling group (a WA
 * message can't be edited across chats), and every other event
 * (claimed/rejected/closed) edits the current card in place so staff can read
 * the latest status without clicking the link. 'transferred' is intentionally
 * untouched - still sends a separate message - since that wasn't part of the
 * "1 ticket = 1 card" request. See the ticketing-plan discussion 2026-10-06.
 */
class NotifyWaTicketLink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45; // 'reminder' and 'approved' each make two sequential WA calls, up to 20s each

    public function __construct(
        public readonly int $ticketId,
        public readonly string $event, // 'created' | 'reminder' | 'approved' | 'rejected' | 'claimed' | 'transferred' | 'closed'
        public readonly string $groupId,
        public readonly int $reminderStage = 0, // 1|2|3, only used for 'reminder'
    ) {}

    public function handle(WhatsAppNotifier $wa): void
    {
        $ticket = MerchantTicket::query()->with(['merchant', 'claimedBy', 'closedBy'])->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        if ($this->event === 'transferred') {
            $this->sendSeparateTransferMessage($wa, $ticket);

            return;
        }

        if ($this->event === 'created') {
            $this->postCard($wa, $ticket, $this->groupId, $this->cardText($ticket));

            return;
        }

        if ($this->event === 'reminder') {
            $this->deleteActiveCard($wa, $ticket);
            $label = match ($this->reminderStage) {
                1 => "\u{23F3} Reminder 1: tiket belum diambil.",
                2 => "\u{23F3} Reminder 2: tiket masih belum diambil.",
                default => "\u{1F6A8} Reminder Final: tiket masih belum diambil.",
            };
            $this->postCard($wa, $ticket, $this->groupId, $label."\n\n".$this->cardText($ticket));

            return;
        }

        if ($this->event === 'approved') {
            $this->editActiveCard($wa, $ticket);
            $ticket->forceFill(['wa_reminder_stage' => 0, 'wa_reminder_stage_at' => now()])->save();
            $this->postCard($wa, $ticket, $this->groupId, $this->cardText($ticket));

            return;
        }

        // rejected | claimed | closed: edit the current active card in place.
        $this->editActiveCard($wa, $ticket);
    }

    private function cardText(MerchantTicket $ticket): string
    {
        $store = $ticket->merchant?->name ?? '-';
        $link = route('wa-tickets.show', $ticket);
        $lines = ["\u{1F3AB} Tiket {$ticket->ticket_no}", "Toko: {$store}", $ticket->description, $link];

        if ($ticket->approval_status === 'rejected') {
            $lines[] = "\u{274C} Ditolak oleh {$ticket->approval_by}.".($ticket->approval_note ? " Catatan: {$ticket->approval_note}" : '');
        } elseif ($ticket->approval_status === 'approved') {
            $lines[] = "\u{2705} Disetujui oleh {$ticket->approval_by}.";
        }

        if ($ticket->claimed_by_user_id) {
            $lines[] = "\u{1F4CC} Diambil oleh {$ticket->claimedBy?->name}.";
        }

        if ($ticket->status === 'closed' && $ticket->approval_status !== 'rejected') {
            $lines[] = "\u{2705} Tiket CLOSED oleh {$ticket->closedBy?->name}.";
        }

        return implode("\n", $lines);
    }

    private function postCard(WhatsAppNotifier $wa, MerchantTicket $ticket, string $groupId, string $text): void
    {
        $messageId = $wa->send($groupId, $text);
        if ($messageId) {
            $ticket->forceFill(['wa_active_chat_id' => $groupId, 'wa_active_message_id' => $messageId])->save();
        }
    }

    private function editActiveCard(WhatsAppNotifier $wa, MerchantTicket $ticket): void
    {
        if ($ticket->wa_active_message_id && $ticket->wa_active_chat_id) {
            $wa->edit($ticket->wa_active_chat_id, $ticket->wa_active_message_id, $this->cardText($ticket));
        }
    }

    private function deleteActiveCard(WhatsAppNotifier $wa, MerchantTicket $ticket): void
    {
        if ($ticket->wa_active_message_id && $ticket->wa_active_chat_id) {
            $wa->delete($ticket->wa_active_chat_id, $ticket->wa_active_message_id);
        }
    }

    private function sendSeparateTransferMessage(WhatsAppNotifier $wa, MerchantTicket $ticket): void
    {
        $link = route('wa-tickets.show', $ticket);
        $store = $ticket->merchant?->name ?? '-';
        $to = $ticket->claimed_by_user_id
            ? "\u{1F4CC} Dipindah ke {$ticket->claimedBy?->name}."
            : "\u{1F501} Dilepas ke antrean.";
        $wa->send($this->groupId, "\u{1F501} Tiket {$ticket->ticket_no} dialihkan.\nToko: {$store}\n{$to}\n{$link}");
    }
}
