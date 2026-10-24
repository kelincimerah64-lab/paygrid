<?php

namespace App\Services\BotTele;

use App\Models\BotSession;
use App\Models\BotTicket;

/**
 * Technician side of a ticket's life: take, start investigation, send update,
 * resolve, failed, PIC takeover, and the IP Whitelist approve/reject gate.
 * Each method names the n8n nodes it replaces. Telegram edits run in the same
 * order as n8n because several of them target the same message.
 */
class TicketFlow extends Flow
{
    public function __construct(TelegramClient $telegram, TicketCards $cards, private SlaCalculator $sla)
    {
        parent::__construct($telegram, $cards);
    }

    /** "Extract Ticket ID" -> "IF Ticket Still Open" -> "GS - Update Ticket Assigned" */
    public function take(array $u, string $ticketId): void
    {
        $ticket = BotTicket::findByTicketId($ticketId);
        if (! $ticket) {
            return;
        }

        $now = BotTime::nowString();
        // Conditional update so two technicians clicking at once cannot both win.
        $taken = BotTicket::query()->where('ticket_id', $ticketId)->where('status', 'OPEN')->update([
            'status' => 'ASSIGNED',
            'assigned_user_id' => $u['user_id'],
            'assigned_name' => $this->displayName($u),
            'assigned_at' => $now,
            'updated_at' => $now,
        ]);

        $ticket->refresh();

        if (! $taken) {
            $this->telegram->sendMessage($u['chat_id'], $this->cards->alreadyTaken($ticket), html: false);

            return;
        }

        $this->telegram->editMessageText($u['chat_id'], $u['message_id'], $this->cards->techAssigned($ticket), TicketCards::startKeyboard($ticketId));
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->merchantAssigned($ticket));

        // "Prepare Delete OPEN Reminders": drop every reminder card except the one that was clicked.
        foreach ($this->reminderIds($ticket) as $messageId) {
            if ($messageId !== (int) $u['message_id']) {
                $this->telegram->deleteMessage($u['chat_id'], $messageId);
            }
        }
    }

    /** "Extract Progress Ticket ID" -> "Status ASSIGNED" -> "IF - Correct Assigned CS" -> "GS - Update ON_PROGRESS" */
    public function startInvestigation(array $u, string $ticketId): void
    {
        $ticket = BotTicket::findByTicketId($ticketId);
        if (! $ticket || $ticket->status !== 'ASSIGNED' || ! $this->isAssignedTo($ticket, $u)) {
            return;
        }

        $now = BotTime::nowString();
        // n8n rewrites assigned_at here too, so investigation_lag ends up ~0 — kept for parity (see docs).
        $ticket->update(['status' => 'ON_PROGRESS', 'assigned_at' => $now, 'started_at' => $now, 'updated_at' => $now]);

        $this->telegram->editMessageText($u['chat_id'], $u['message_id'], $this->cards->techProgressClicked($ticket), TicketCards::actionKeyboard($ticketId));
        $this->telegram->editMessageText($this->techChatId(), $ticket->cs_group_message_id, $this->cards->techProgressGroup($ticket), []);
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->merchantProgress($ticket));
        $this->telegram->editMessageText($u['chat_id'], $u['message_id'], $this->cards->merchantProgress($ticket), TicketCards::actionKeyboard($ticketId));
    }

    /** "Extract Update Ticket ID" -> "SET - CS Update Session" -> "TG - Notify Merchant ON_PROGRESS1" */
    public function askUpdate(array $u, string $ticketId): void
    {
        $this->askForText($u, $ticketId, 'WAITING_CS_UPDATE', 'CS_UPDATE', fn (BotTicket $t): string => $this->cards->updatePrompt($t));
    }

    /** "Build CS Update" -> update last_update -> refresh the three cards. */
    public function receiveUpdate(array $u, BotSession $session): void
    {
        $ticket = BotTicket::findByTicketId((string) $session->temp_ticket_id);
        if (! $ticket) {
            return;
        }

        $text = (string) $u['text'];
        $ticket->update(['last_update' => $text, 'updated_at' => BotTime::nowString()]);

        $this->telegram->editMessageText($this->techChatId(), $ticket->cs_group_message_id, $this->cards->techUpdate($ticket, $text), TicketCards::actionKeyboard($ticket->ticket_id));
        // n8n sent this edit to the tech chat with the merchant's message id, so it never landed; fixed to the merchant chat.
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->merchantUpdate($ticket, $text));
        $session->delete();
        $this->telegram->editMessageText($session->resolve_chat_id, $session->resolve_message_id, $this->cards->clickedUpdate($ticket, $text), TicketCards::actionKeyboard($ticket->ticket_id));
    }

    /** "Extract Resolve Ticket ID" -> "ON_PROGRESS" -> "IF - Resolve By Assigned CS" -> "Build Direct Resolution" */
    public function resolve(array $u, string $ticketId): void
    {
        $ticket = BotTicket::findByTicketId($ticketId);
        if (! $ticket || $ticket->status !== 'ON_PROGRESS' || ! $this->isAssignedTo($ticket, $u)) {
            return;
        }

        $now = BotTime::nowString();
        $ticket->update([
            'status' => 'RESOLVED',
            'last_update' => null,
            'completed_at' => $now,
            'updated_at' => $now,
            ...$this->sla->calculate($ticket->created_at, $ticket->assigned_at, $ticket->started_at, $now),
        ]);
        $this->releaseTechnician($ticket->assigned_user_id);

        $this->telegram->editMessageText($u['chat_id'], $u['message_id'], $this->cards->techResolved($ticket), []);
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->merchantResolved($ticket));
    }

    /** "Extract Failed Ticket ID" -> "SET - Failed Session" -> "TG - Notify Merchant SOLVE1" */
    public function askFailedReason(array $u, string $ticketId): void
    {
        $this->askForText($u, $ticketId, 'WAITING_FAILED_REASON', 'FAILED', fn (BotTicket $t): string => $this->cards->failedPrompt($t));
    }

    /** "Build Failed Result" -> "Calculate SLA1" -> "GS - Mark RESOLVED1" (which really sets FAILED). */
    public function receiveFailedReason(array $u, BotSession $session): void
    {
        $ticket = BotTicket::findByTicketId((string) $session->temp_ticket_id);
        if (! $ticket) {
            return;
        }

        $reason = (string) $u['text'];
        $now = BotTime::nowString();
        // n8n wrote completed_at into total_resolution_minutes here; the computed seconds are stored instead.
        $ticket->update([
            'status' => 'FAILED',
            'last_update' => $reason,
            'completed_at' => $now,
            'updated_at' => $now,
            ...$this->sla->calculate($ticket->created_at, $ticket->assigned_at, $ticket->started_at, $now),
        ]);
        $this->releaseTechnician($ticket->assigned_user_id);

        $this->telegram->editMessageText($this->techChatId(), $ticket->cs_group_message_id, $this->cards->techFailed($ticket, $reason), []);
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->merchantFailed($ticket, $reason));
        $session->delete();
        $this->telegram->editMessageText($session->resolve_chat_id, $session->resolve_message_id, $this->cards->clickedFailed($ticket, $reason), []);
    }

    /** "Extract PIC Progress Ticket ID" -> "IF - PIC Can Take Over?" -> "GS - PIC Take Over Ticket" */
    public function picTakeOver(array $u, string $ticketId): void
    {
        $ticket = BotTicket::findByTicketId($ticketId);
        $canTakeOver = $ticket
            && strtoupper((string) $ticket->status) === 'ASSIGNED'
            && strtoupper((string) $ticket->assignment_type) === 'AUTO'
            && $ticket->escalated === true
            && trim((string) $ticket->started_at) === '';

        if (! $canTakeOver) {
            return;
        }

        $previousTechnician = $ticket->assigned_user_id;
        $now = BotTime::nowString();
        $ticket->update([
            'status' => 'ON_PROGRESS',
            'assigned_user_id' => $u['user_id'],
            'assigned_name' => $this->displayName($u),
            'started_at' => $now,
            'updated_at' => $now,
            'last_update' => 'Investigation started by PIC.',
        ]);
        $this->releaseTechnician($previousTechnician);

        $this->telegram->editMessageText($u['chat_id'], $u['message_id'], $this->cards->picClicked($ticket), TicketCards::actionKeyboard($ticketId));
        $this->telegram->editMessageText($this->techChatId(), $ticket->cs_group_message_id, $this->cards->picGroup($ticket), []);
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->picMerchant($ticket));
    }

    /** "Extract IP Approval Ticket ID" -> "IF - IP Still Waiting Approval?" -> "Build Direct IP Approval" */
    public function approveIp(array $u, string $ticketId): void
    {
        $ticket = BotTicket::findByTicketId($ticketId);
        if (! $ticket || ! $this->awaitingApproval($ticket)) {
            return;
        }

        $now = BotTime::nowString();
        $ticket->update([
            'status' => 'OPEN',
            'approval_status' => 'APPROVED',
            'approval_by_user_id' => $u['user_id'],
            'approval_by_name' => $this->displayName($u),
            'approval_note' => null,
            'approval_completed_at' => $now,
            'updated_at' => $now,
        ]);

        $this->telegram->editMessageText($u['chat_id'], $u['message_id'], $this->cards->ipApprovedApprovalCard($ticket), []);
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->ipApprovedMerchant($ticket));

        $this->pushApprovedToTech($ticket);
    }

    /**
     * "TG - Push Approved IP Ticket" + save cs_group_message_id: an approved IP Whitelist ticket joins
     * the normal queue with a Take button. Shared by the Telegram Approve button and the WAHA poll vote.
     */
    public function pushApprovedToTech(BotTicket $ticket): void
    {
        $techMessageId = $this->telegram->sendMessage($this->techChatId(), $this->cards->ipApprovedTech($ticket), TicketCards::takeKeyboard($ticket->ticket_id));
        $ticket->update(['cs_group_message_id' => $techMessageId]);
    }

    /** "Extract IP Reject Ticket ID" -> "SET - IP Rejection Session" -> "TG - Ask IP Rejection Note" */
    public function askRejectionNote(array $u, string $ticketId): void
    {
        $ticket = BotTicket::findByTicketId($ticketId);
        if (! $ticket || ! $this->awaitingApproval($ticket)) {
            return;
        }

        $this->rememberPending($u, $ticket, 'WAITING_IP_REJECTION_NOTE', SupportFlow::IP_WHITELIST);
        $this->telegram->sendMessage($u['chat_id'], $this->cards->rejectionPrompt($ticket));
    }

    /** "Build IP Rejection Result" -> "IF - IP Ticket Still Waiting For Rejection" -> "GS - Reject IP Ticket" */
    public function receiveRejectionNote(array $u, BotSession $session): void
    {
        $ticket = BotTicket::findByTicketId((string) $session->temp_ticket_id);
        if (! $ticket || ! $this->awaitingApproval($ticket)) {
            return;
        }

        $now = BotTime::nowString();
        $ticket->update([
            'status' => 'REJECTED',
            'approval_status' => 'REJECTED',
            'approval_by_user_id' => $u['user_id'],
            'approval_by_name' => $this->displayName($u),
            'approval_note' => (string) $u['text'],
            'approval_completed_at' => $now,
            'updated_at' => $now,
        ]);

        // No reply_markup, so Telegram drops the Approve/Reject buttons.
        $this->telegram->editMessageText($session->resolve_chat_id, $session->resolve_message_id, $this->cards->ipRejectedApprovalCard($ticket));
        $this->telegram->editMessageText($ticket->source_chat_id, $ticket->merchant_message_id, $this->cards->ipRejectedMerchant($ticket));
        $session->delete();
    }

    /** Shared by Send Update and Failed: only the assigned technician of an ON_PROGRESS ticket gets the prompt. */
    private function askForText(array $u, string $ticketId, string $state, string $category, \Closure $prompt): void
    {
        $ticket = BotTicket::findByTicketId($ticketId);
        if (! $ticket || $ticket->status !== 'ON_PROGRESS' || ! $this->isAssignedTo($ticket, $u)) {
            return;
        }

        $this->rememberPending($u, $ticket, $state, $category);
        $this->telegram->sendMessage($u['chat_id'], $prompt($ticket));
    }

    /** The next text this user sends in this chat answers the prompt; resolve_* points at the clicked card. */
    private function rememberPending(array $u, BotTicket $ticket, string $state, string $category): void
    {
        BotSession::upsertFor($u['user_id'], $u['chat_id'], [
            'state' => $state,
            'category' => $category,
            'temp_ticket_id' => $ticket->ticket_id,
            'resolve_chat_id' => $this->nullable($u['chat_id']),
            'resolve_message_id' => $this->nullable($u['message_id']),
            'updated_at' => BotTime::nowString(),
        ]);
    }

    private function awaitingApproval(BotTicket $ticket): bool
    {
        return $ticket->status === 'WAITING_APPROVAL' && $ticket->approval_status === 'WAITING_APPROVAL';
    }
}
