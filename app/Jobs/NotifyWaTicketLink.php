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
 * One-way only - sends a notification + link, never reads anything back from
 * WhatsApp. All real work (claim, reply, transfer, approve/reject, close)
 * happens on the web after the link is clicked. See the "link-first"
 * ticketing plan. $groupId is resolved by the caller (approval group vs
 * handling group) since that depends on ticket state the job itself
 * shouldn't need to re-derive.
 */
class NotifyWaTicketLink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $event, // 'created' | 'approved' | 'transferred' | 'reminder'
        public readonly string $groupId,
    ) {}

    public function handle(WhatsAppNotifier $wa): void
    {
        $ticket = MerchantTicket::query()->with('merchant')->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        $link = route('wa-tickets.show', $ticket);
        $store = $ticket->merchant?->name ?? '-';

        $message = match ($this->event) {
            'approved' => "\u{2705} {$ticket->ticket_no} disetujui oleh {$ticket->approval_by}. Siap diproses.\nToko: {$store}\n{$link}",
            'transferred' => "\u{1F501} Tiket {$ticket->ticket_no} dialihkan.\nToko: {$store}\n{$link}",
            'reminder' => "\u{23F3} Reminder: {$ticket->ticket_no} belum ada yang ambil.\nToko: {$store}\n{$link}",
            default => "\u{1F3AB} Tiket baru {$ticket->ticket_no}\nToko: {$store}\n{$ticket->description}\n{$link}",
        };

        $wa->send($this->groupId, $message);
    }
}
