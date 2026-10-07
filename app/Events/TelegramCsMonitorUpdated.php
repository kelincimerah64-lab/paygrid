<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lightweight "something changed" ping for the CS Monitor dashboard - no
 * payload, the client just re-runs its existing fetch-and-diff refresh() on
 * receipt (same one the periodic poll uses). Fired whenever an n8n callback
 * or an admin action changes what that dashboard shows, so viewers update
 * instantly instead of waiting for the next poll tick.
 *
 * ShouldBroadcastNow (not ShouldBroadcast) deliberately skips the queue -
 * this app's queue workers poll Redis with a non-blocking pop + sleep
 * (config/queue.php block_for is null), so a queued broadcast could sit
 * for seconds behind whatever else is already on that worker's queue.
 * Broadcasting inline adds one fast HTTP call to Reverb to the request,
 * which is the right trade for a ping that only matters if it's instant.
 */
class TelegramCsMonitorUpdated implements ShouldBroadcastNow
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
