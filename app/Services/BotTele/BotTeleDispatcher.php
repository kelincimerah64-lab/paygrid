<?php

namespace App\Services\BotTele;

use App\Models\BotSession;

/**
 * Entry point for one Telegram update: normalize, then route the way the n8n
 * "Event Router" -> "Message Router"/"Message Router1" -> "Switch" chain did.
 */
class BotTeleDispatcher
{
    public function __construct(
        private UpdateNormalizer $normalizer,
        private UpdateRouter $router,
        private SupportFlow $support,
        private TicketFlow $tickets,
        private StaffFlow $staff,
    ) {}

    public function handle(array $update): void
    {
        $u = $this->normalizer->normalize($update);

        match ($u['event_type']) {
            'message' => $this->handleMessage($u),
            'callback' => $this->handleCallback($u),
            default => null,
        };
    }

    private function handleMessage(array $u): void
    {
        match ($this->router->messageRoute($u, config('bottele.admin_chat_id'))) {
            'support' => $this->support->showMenu($u),
            'checkticket' => $this->staff->checkTickets(),
            'absen' => $this->staff->checkIn($u),
            'default' => $this->continueConversation($u),
            default => null,
        };
    }

    /** "GS - Find Session" -> "Switch": a plain message answers whatever the session is waiting for. */
    private function continueConversation(array $u): void
    {
        $session = BotSession::for($u['user_id'], $u['chat_id']);
        if (! $session) {
            return;
        }

        $state = (string) $session->state;
        if (in_array($state, SupportFlow::DESCRIPTION_STATES, true)) {
            $this->support->receiveDescription($u, $session);

            return;
        }

        // WAITING_RESOLUTION and WAITING_IP_APPROVAL_NOTE are dead ends in n8n (nothing ever sets them,
        // and their branches read nodes that never ran), so they are ignored here too.
        match ($state) {
            'WAITING_CREATE_USER_DETAILS' => $this->support->receiveCreateUser($u, $session),
            'WAITING_CS_UPDATE' => $this->tickets->receiveUpdate($u, $session),
            'WAITING_FAILED_REASON' => $this->tickets->receiveFailedReason($u, $session),
            'WAITING_IP_REJECTION_NOTE' => $this->tickets->receiveRejectionNote($u, $session),
            default => null,
        };
    }

    private function handleCallback(array $u): void
    {
        $match = $this->router->callbackRoute((string) $u['callback_data']);
        if (! $match) {
            return;
        }

        $ticketId = $match['ticket_id'];

        match ($match['route']) {
            'ticket_dashboard', 'ticket_api', 'ticket_whitelist', 'ticket_other', 'ticket_create_user' => $this->support->chooseCategory($u, $match['route']),
            'take' => $this->tickets->take($u, $ticketId),
            'reject' => $this->tickets->reject($u, $ticketId),
            'progress' => $this->tickets->startInvestigation($u, $ticketId),
            'update' => $this->tickets->askUpdate($u, $ticketId),
            'resolve' => $this->tickets->resolve($u, $ticketId),
            'failed' => $this->tickets->askFailedReason($u, $ticketId),
            'picprogress' => $this->tickets->picTakeOver($u, $ticketId),
            'ipapprove' => $this->tickets->approveIp($u, $ticketId),
            'ipreject' => $this->tickets->askRejectionNote($u, $ticketId),
        };
    }
}
