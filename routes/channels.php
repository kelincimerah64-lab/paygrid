<?php

use App\Models\Merchant;
use App\Models\MerchantTicket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Same access rule as MaWaTicketController::scopedMerchantIds() for staff,
 * and MerchantScopeMiddleware's merchant_id match for the toko portal side -
 * duplicated here (rather than extracted into a shared helper) since this
 * callback can't reach either controller's private method.
 */
Broadcast::channel('ticket.{ticketId}', function (User $user, int $ticketId) {
    $ticket = MerchantTicket::find($ticketId);
    if (! $ticket) {
        return false;
    }

    if (in_array($user->role, ['superadmin', 'cs_pusat', 'approver'], true)) {
        return true;
    }

    if ($user->role === 'ma') {
        return Merchant::whereRelation('agent', 'ma_user_id', $user->id)->where('id', $ticket->merchant_id)->exists();
    }

    return (int) $user->merchant_id === (int) $ticket->merchant_id;
});
