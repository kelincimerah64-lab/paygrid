<?php

namespace App\Events;

use App\Models\MerchantTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lightweight "new ticket" ping for the department queue list, same
 * ping-only shape as MerchantTicketMessageSent - the client just reloads/
 * plays a sound on receipt rather than carrying ticket data over the socket.
 */
class MerchantTicketCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $ticketId, public readonly string $department) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('department-tickets.'.$this->department)];
    }

    public function broadcastAs(): string
    {
        return 'ticket.created';
    }

    public static function forTicket(MerchantTicket $ticket): self
    {
        return new self($ticket->id, $ticket->department);
    }
}
