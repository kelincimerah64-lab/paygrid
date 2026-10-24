<?php

namespace App\Services\BotTele;

use App\Models\BotTicket;
use App\Models\MerchantTicket;
use App\Services\MerchantTicketService;
use Illuminate\Support\Facades\Log;

/**
 * Port of "PayGrid - IP Whitelist WA Approval": a web IP Whitelist ticket gets a
 * WhatsApp approval poll plus a mirror row in bot_tickets; the vote (or the
 * dashboard Approve/Reject, whichever comes first) decides both sides, and an
 * approval pushes the Telegram "Take Ticket" card like a Telegram approval does.
 *
 * TODO(verify against real WAHA): the poll id path in the sendPoll response and the
 * poll.vote payload paths below are the same guesses n8n made. Unknown shapes are
 * logged in full (bottele.waha_unrecognized_*) instead of failing silently.
 */
class IpWhitelistBridge
{
    public const APPROVE = 'Approve';

    public const REJECT = 'Reject';

    public function __construct(
        private WahaClient $waha,
        private TicketFlow $tickets,
        private MerchantTicketService $merchantTickets,
    ) {}

    /** Alur A: "Build Info + Poll" -> sendText -> sendPoll -> "Extract Poll ID" -> append bot ticket. */
    public function requestApproval(MerchantTicket $ticket): BotTicket
    {
        $ticket->loadMissing(['merchant.agent', 'createdBy']);
        $merchantGroup = $ticket->merchant?->agent?->name ?: '-';
        $reportedBy = $ticket->createdBy?->name ?: '-';
        $groupId = (string) config('bottele.waha.ip_approval_group_id');

        $this->waha->sendText($groupId, TicketCards::waApprovalInfo($ticket->ticket_no, $merchantGroup, $reportedBy, (string) $ticket->merchant?->name, (string) $ticket->description));
        $response = $this->waha->sendPoll($groupId, 'Approve tiket '.$ticket->ticket_no.'?', [self::APPROVE, self::REJECT]);

        $pollId = WahaClient::pollId($response);
        if ($pollId === '') {
            Log::warning('bottele.waha_unrecognized_poll_response', ['merchant_ticket' => $ticket->ticket_no, 'response' => $response]);
        }

        $now = BotTime::nowString();
        $botTicket = BotTicket::query()->create([
            'ticket_id' => BotTicket::newTicketId(),
            'created_at' => $now,
            'source_chat_title' => $merchantGroup,
            'requester_name' => $reportedBy,
            'category' => SupportFlow::IP_WHITELIST,
            'description' => $ticket->description,
            'status' => 'WAITING_APPROVAL',
            'approval_status' => 'WAITING_APPROVAL',
            'approval_group_message_id' => $pollId !== '' ? $pollId : null,
            'approval_requested_at' => $now,
            'updated_at' => $now,
        ]);

        $ticket->forceFill(['bot_ticket_id' => $botTicket->ticket_id])->save();

        return $botTicket;
    }

    /**
     * Alur B: one WAHA webhook event. Returns what happened, for the response body and logs.
     *
     * @return 'ignored'|'unrecognized'|'no_ticket'|'already_decided'|'approved'|'rejected'
     */
    public function handleWebhook(array $event): string
    {
        // "Normalize Poll Vote". WAHA sends every subscribed event here; only poll votes matter.
        if (data_get($event, 'event') !== 'poll.vote') {
            return 'ignored';
        }

        $pollId = data_get($event, 'payload.poll.id._serialized') ?? data_get($event, 'payload.poll.id');
        $selected = data_get($event, 'payload.vote.selectedOptions.0');
        $selected = is_array($selected) ? ($selected['name'] ?? null) : $selected;
        $voterId = (string) (data_get($event, 'payload.vote.from') ?? data_get($event, 'payload.vote.participant') ?? '');

        if (! is_string($pollId) || $pollId === '') {
            Log::warning('bottele.waha_unrecognized_poll_vote', ['payload' => $event]);

            return 'unrecognized';
        }

        $ticket = BotTicket::query()->where('approval_group_message_id', $pollId)->first();
        if (! $ticket) {
            // Other polls in the same WhatsApp group are normal, not an error.
            Log::info('bottele.waha_vote_without_ticket', ['poll_id' => $pollId]);

            return 'no_ticket';
        }

        // n8n treated anything that was not "Approve" (even a retracted vote) as Reject; only the two real options count here.
        if (! in_array($selected, [self::APPROVE, self::REJECT], true)) {
            Log::warning('bottele.waha_unrecognized_poll_vote', ['payload' => $event]);

            return 'unrecognized';
        }

        if (! $this->awaitingApproval($ticket)) {
            return 'already_decided';
        }

        $voterName = $this->waha->contactName($voterId)
            ?? data_get($event, 'payload.vote.notifyName')
            ?? data_get($event, 'payload.vote._data.notifyName')
            ?: $voterId;

        $approved = $selected === self::APPROVE;
        $this->decide($ticket, $approved, (string) $voterName);

        // "Callback PayGrid - Approved" used to POST /api/n8n/tickets/{no}/approval; same logic, called directly.
        // n8n only called back on approval; the rejection is mirrored too so the web ticket does not wait forever.
        $merchantTicket = MerchantTicket::query()->where('bot_ticket_id', $ticket->ticket_id)->first();
        if ($merchantTicket) {
            $this->merchantTickets->applyExternalApproval($merchantTicket, $approved ? 'approved' : 'rejected', (string) $voterName);
        }

        return $approved ? 'approved' : 'rejected';
    }

    /** Dashboard Approve/Reject came first: mirror it onto the bot ticket so the poll can no longer decide. */
    public function syncFromDashboard(MerchantTicket $ticket): void
    {
        if (! $ticket->bot_ticket_id || ! in_array($ticket->approval_status, ['approved', 'rejected'], true)) {
            return;
        }

        $botTicket = BotTicket::findByTicketId($ticket->bot_ticket_id);
        if ($botTicket && $this->awaitingApproval($botTicket)) {
            $this->decide($botTicket, $ticket->approval_status === 'approved', (string) $ticket->approval_by, $ticket->approval_note);
        }
    }

    /** "GS - Update Row Approved/Rejected" (+ "TG - Push Approved IP Ticket" on approval). */
    private function decide(BotTicket $ticket, bool $approved, string $by, ?string $note = null): void
    {
        $now = BotTime::nowString();
        $ticket->update([
            'status' => $approved ? 'OPEN' : 'REJECTED',
            'approval_status' => $approved ? 'APPROVED' : 'REJECTED',
            'approval_by_name' => $by,
            'approval_note' => $note,
            'approval_completed_at' => $now,
            'updated_at' => $now,
        ]);

        if ($approved) {
            $this->tickets->pushApprovedToTech($ticket);
        }
    }

    private function awaitingApproval(BotTicket $ticket): bool
    {
        return $ticket->status === 'WAITING_APPROVAL' && $ticket->approval_status === 'WAITING_APPROVAL';
    }
}
