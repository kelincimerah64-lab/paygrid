<?php

namespace App\Services;

use App\Models\NotificationRead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracks, per user, when each sidebar badge source was last visited - so a
 * badge counts only items that showed up since the user last looked, and
 * drops to zero right after they click into that menu.
 */
class NotificationReadService
{
    public function lastSeenAt(User $user, string $key): ?Carbon
    {
        return NotificationRead::query()
            ->where('user_id', $user->id)
            ->where('source_key', $key)
            ->value('last_seen_at');
    }

    public function markSeen(User $user, string $key): void
    {
        NotificationRead::query()->updateOrCreate(
            ['user_id' => $user->id, 'source_key' => $key],
            ['last_seen_at' => now()],
        );
    }

    /**
     * Counts rows in $query newer than the user's last-seen timestamp for
     * $key (on $freshnessColumn), scoped before counting. Everything counts
     * if the user has never visited this source before.
     */
    public function unseenCount(User $user, string $key, Builder $query, string $freshnessColumn): int
    {
        $lastSeen = $this->lastSeenAt($user, $key);
        if ($lastSeen) {
            $query->where($freshnessColumn, '>', $lastSeen);
        }

        return $query->count();
    }
}
