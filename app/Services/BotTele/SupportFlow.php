<?php

namespace App\Services\BotTele;

use App\Models\BotSession;
use App\Models\BotTicket;

/**
 * Merchant side: /support menu, category pick, description, Create User
 * validation and the generic "Generate Ticket" pipeline every category ends in.
 */
class SupportFlow extends Flow
{
    /** Menu callback => [session state, ticket category]. */
    public const CATEGORIES = [
        'ticket_dashboard' => ['WAITING_DASHBOARD_DESCRIPTION', 'Merchant Details | User Role'],
        'ticket_api' => ['WAITING_API_DESCRIPTION', 'Technical Issue'],
        'ticket_whitelist' => ['WAITING_WHITELIST_DESCRIPTION', 'IP Whitelist / VPN'],
        'ticket_other' => ['WAITING_OTHER_DESCRIPTION', 'Others'],
        'ticket_create_user' => ['WAITING_CREATE_USER_DETAILS', 'Create User'],
    ];

    public const DESCRIPTION_STATES = [
        'WAITING_DASHBOARD_DESCRIPTION',
        'WAITING_API_DESCRIPTION',
        'WAITING_WHITELIST_DESCRIPTION',
        'WAITING_OTHER_DESCRIPTION',
    ];

    public const IP_WHITELIST = 'IP Whitelist / VPN';

    public function __construct(TelegramClient $telegram, TicketCards $cards, private CreateUserParser $createUser)
    {
        parent::__construct($telegram, $cards);
    }

    /** "TG - Send Support Menu" + "GS - Create Support Cleanup Session" */
    public function showMenu(array $u): void
    {
        $menuId = $this->telegram->sendMessage($u['chat_id'], TicketCards::SUPPORT_MENU, TicketCards::menuKeyboard(), html: false);

        BotSession::upsertFor($u['user_id'], $u['chat_id'], [
            'category' => null,
            'support_command_message_id' => $this->nullable($u['message_id']),
            'support_menu_message_id' => $menuId,
            'support_prompt_message_id' => null,
            'user_detail_message_id' => null,
            'state' => 'SUPPORT_MENU',
            'updated_at' => BotTime::nowString(),
        ]);
    }

    /** "SET - * Session" -> ask description -> "GS - Store * Prompt" -> "TG - Lock Category Menu" */
    public function chooseCategory(array $u, string $route): void
    {
        [$state, $category] = self::CATEGORIES[$route];

        $promptId = $this->telegram->sendMessage($u['chat_id'], TicketCards::CATEGORY_PROMPTS[$category], html: false);

        BotSession::upsertFor($u['user_id'], $u['chat_id'], [
            'state' => $state,
            'category' => $category,
            'support_prompt_message_id' => $promptId,
            'updated_at' => BotTime::nowString(),
        ]);

        $this->telegram->removeKeyboard($u['chat_id'], $u['message_id']);
    }

    /**
     * n8n also wrote user_detail_message_id here ("GS - Store User Detail Message"), but the
     * session row is deleted moments later, so that write is skipped.
     */
    public function receiveDescription(array $u, BotSession $session): void
    {
        $this->createTicket($u, $session, $u['text']);
    }

    /** "Code - Validate Create User" -> "IF - Create User Valid" */
    public function receiveCreateUser(array $u, BotSession $session): void
    {
        $result = $this->createUser->parse((string) $u['text']);

        if (! $result['valid']) {
            // The session stays on WAITING_CREATE_USER_DETAILS so the user can simply resend.
            $this->telegram->sendMessage($u['chat_id'], TicketCards::createUserError($result['missing']), html: false);

            return;
        }

        $this->createTicket($u, $session, $result['description']);
    }

    /** "Generate Ticket" -> "GS - Create Ticket" -> confirm, clean up, push to the right group. */
    public function createTicket(array $u, BotSession $session, string $description): BotTicket
    {
        $fallback = array_column(array_slice(self::CATEGORIES, 0, 4), 1, 0);
        $category = $session->category ?: ($fallback[$session->state] ?? '-');
        $isIpWhitelist = trim($category) === self::IP_WHITELIST;
        $now = BotTime::nowString();

        $ticket = BotTicket::query()->create([
            'ticket_id' => BotTicket::newTicketId(),
            'created_at' => $now,
            'source_chat_id' => $this->nullable($u['chat_id']),
            'source_chat_title' => $this->nullable($u['chat_title']),
            'requester_user_id' => $this->nullable($u['user_id']),
            'requester_name' => $this->nullable($this->displayName($u)),
            'requester_username' => $this->nullable($u['username']),
            'category' => $category,
            'description' => $description,
            'attachment_file_id' => $this->nullable($u['photo_file_id']),
            'has_attachment' => (bool) $u['has_photo'],
            'status' => $isIpWhitelist ? 'WAITING_APPROVAL' : 'OPEN',
            'approval_status' => $isIpWhitelist ? 'WAITING_APPROVAL' : null,
            'approval_requested_at' => $isIpWhitelist ? $now : null,
        ]);

        $merchantMessageId = $this->telegram->sendMessage($ticket->source_chat_id, $this->cards->merchantCreated($ticket));

        // "TG - Delete Support Command/Menu/Prompt/User Detail": clear the conversation once the ticket exists.
        $this->telegram->deleteMessage($session->chat_id, $session->support_command_message_id);
        $this->telegram->deleteMessage($session->chat_id, $session->support_menu_message_id);
        $this->telegram->deleteMessage($session->chat_id, $session->support_prompt_message_id);
        $this->telegram->deleteMessage($u['chat_id'], $u['message_id']);
        $session->delete();

        $ticket->update(['merchant_message_id' => $merchantMessageId]);

        if ($isIpWhitelist) {
            $approvalMessageId = $this->telegram->sendMessage(
                config('bottele.ip_approval_chat_id'),
                $this->cards->ipApprovalRequest($ticket),
                TicketCards::approvalKeyboard($ticket->ticket_id),
            );
            $ticket->update(['approval_group_message_id' => $approvalMessageId, 'updated_at' => BotTime::nowString()]);

            return $ticket;
        }

        $techMessageId = $this->telegram->sendMessage($this->techChatId(), $this->cards->techCreated($ticket), TicketCards::takeKeyboard($ticket->ticket_id));
        $ticket->update(['cs_group_message_id' => $techMessageId]);

        if ($ticket->has_attachment) {
            $this->telegram->sendPhoto($this->techChatId(), (string) $ticket->attachment_file_id, $this->cards->screenshotCaption($ticket));
        }

        return $ticket;
    }
}
