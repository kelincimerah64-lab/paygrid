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
 * WhatsApp. All real work (claim, reply, transfer, close) happens on the web
 * after the link is clicked. See the "link-first" ticketing plan.
 */
class NotifyWaTicketLink implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $event, // 'created' | 'transferred' | 'reminder'
    ) {}

    public function handle(WhatsAppNotifier $wa): void
    {
        $ticket = MerchantTicket::query()->with('merchant')->find($this->ticketId);
        $groupId = config('paygrid.whatsapp.ticket_group_id');

        if (! $ticket || ! $groupId) {
            return;
        }

        $link = route('ma.wa-tickets.show', $ticket);
        $store = $ticket->merchant?->name ?? '-';

        $message = match ($this->event) {
            'transferred' => "\u{1F501} Tiket {$ticket->ticket_no} dialihkan.\nToko: {$store}\n{$link}",
            'reminder' => "\u{23F3} Reminder: {$ticket->ticket_no} belum ada yang ambil.\nToko: {$store}\n{$link}",
            default => "\u{1F3AB} Tiket baru {$ticket->ticket_no}\nToko: {$store}\n{$ticket->description}\n{$link}",
        };

        $wa->send($groupId, $message);
    }
}
