<?php

namespace App\Services\BotTele;

/**
 * Port of the n8n "Message Router" and "Message Router1" switches. Like n8n,
 * the first matching rule wins, so rule order matters.
 */
class UpdateRouter
{
    // [route, match type, value] — callbacks carry the ticket id after the prefix.
    private const CALLBACK_RULES = [
        ['ticket_dashboard', 'equals', 'ticket_dashboard'],
        ['ticket_api', 'equals', 'ticket_api'],
        ['ticket_whitelist', 'equals', 'ticket_whitelist'],
        ['ticket_other', 'equals', 'ticket_other'],
        ['take', 'prefix', 'take_'],
        ['progress', 'prefix', 'progress_'],
        ['update', 'prefix', 'update_'],
        ['resolve', 'prefix', 'resolve_'],
        ['failed', 'prefix', 'failed_'],
        ['picprogress', 'iprefix', 'picprogress_'],
        ['ipapprove', 'prefix', 'ipapprove_'],
        ['ipreject', 'prefix', 'ipreject_'],
        ['ticket_create_user', 'equals', 'ticket_create_user'],
        ['reject', 'prefix', 'reject_'],
    ];

    /** @return 'support'|'checkticket'|'absen'|'default'|null */
    public function messageRoute(array $update, mixed $adminChatId): ?string
    {
        $text = (string) $update['text'];
        $inAdminChat = (string) $update['chat_id'] === (string) $adminChatId;

        if (preg_match('/^\/support(?:@\w+)?$/iD', $text) && ! $inAdminChat) {
            return 'support';
        }
        if (preg_match('/^\/checkticket(?:@\w+)?$/iD', $text) && $inAdminChat) {
            return 'checkticket';
        }
        if (strtolower(preg_replace('/^\/+/', '', trim($text))) === 'absen' && $update['chat_type'] === 'private') {
            return 'absen';
        }

        return $text !== '' ? 'default' : null;
    }

    /** @return array{route: string, ticket_id: string}|null */
    public function callbackRoute(string $data): ?array
    {
        foreach (self::CALLBACK_RULES as [$route, $type, $value]) {
            $matched = match ($type) {
                'equals' => $data === $value,
                'prefix' => str_starts_with($data, $value),
                'iprefix' => stripos($data, $value) === 0,
            };

            if ($matched) {
                return ['route' => $route, 'ticket_id' => $type === 'equals' ? '' : substr($data, strlen($value))];
            }
        }

        return null;
    }
}
