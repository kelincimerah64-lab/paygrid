<?php

use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Same access rule as MerchantScopeMiddleware's merchant_id match for the
 * toko portal side - duplicated here (rather than extracted into a shared
 * helper) since this callback can't reach that middleware's logic directly.
 */
Broadcast::channel('ticket.{ticketId}', function (User $user, int $ticketId) {
    $ticket = MerchantTicket::find($ticketId);
    if (! $ticket) {
        return false;
    }

    if (in_array($user->role, ['superadmin', 'cs_pusat'], true)) {
        return true;
    }

    if (str_starts_with($user->role, 'dept_')) {
        return $ticket->department === substr($user->role, 5);
    }

    if ($user->role === 'ma') {
        return Merchant::whereRelation('agent', 'ma_user_id', $user->id)->where('id', $ticket->merchant_id)->exists();
    }

    return (int) $user->merchant_id === (int) $ticket->merchant_id;
});

/**
 * New-ticket ping for the department queue list - same access rule as
 * ticket.{ticketId} above, just scoped to a department rather than a
 * specific ticket (since the ticket doesn't exist client-side yet to check).
 */
Broadcast::channel('department-tickets.{department}', function (User $user, string $department) {
    if (in_array($user->role, ['superadmin', 'cs_pusat'], true)) {
        return true;
    }

    return str_starts_with($user->role, 'dept_') && substr($user->role, 5) === $department;
});

Broadcast::channel('cs-monitor', function (User $user) {
    return in_array($user->role, ['cs_monitor', 'superadmin'], true);
});
