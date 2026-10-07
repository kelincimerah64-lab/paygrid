<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lightweight "something changed" ping for the CS Monitor dashboard - no
 * payload, the client just re-runs its existing fetch-and-diff refresh() on
 * receipt (same one the periodic poll uses). Fired whenever an n8n callback
 * or an admin action changes what that dashboard shows, so viewers update
 * instantly instead of waiting for the next poll tick.
 */
class TelegramCsMonitorUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function broadcastOn(): array
    {
        return [new PrivateChannel('cs-monitor')];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }
}
