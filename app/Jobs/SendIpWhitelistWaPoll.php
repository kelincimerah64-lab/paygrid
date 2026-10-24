<?php

namespace App\Jobs;

use App\Models\MerchantTicket;
use App\Services\BotTele\IpWhitelistBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sends the WhatsApp approval poll for a new web IP Whitelist ticket and mirrors it into bot_tickets. */
class SendIpWhitelistWaPoll implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A retry would post a second poll to the group.
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $ticketId) {}

    public function handle(IpWhitelistBridge $bridge): void
    {
        $ticket = MerchantTicket::query()->find($this->ticketId);

        if ($ticket && ! $ticket->bot_ticket_id && $ticket->approval_status === 'waiting') {
            $bridge->requestApproval($ticket);
        }
    }
}
