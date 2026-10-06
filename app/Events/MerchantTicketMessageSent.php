<?php

namespace App\Events;

use App\Models\MerchantTicketMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lightweight "something changed" ping, not a payload carrying the message
 * itself - the client re-runs its existing fetch-and-diff refresh() on
 * receipt (same one the periodic poll uses), so there's one rendering path
 * and nothing sensitive travels over the socket beyond the ticket id.
 */
class MerchantTicketMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $ticketId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ticket.'.$this->ticketId)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public static function forMessage(MerchantTicketMessage $message): self
    {
        return new self($message->merchant_ticket_id);
    }
}
