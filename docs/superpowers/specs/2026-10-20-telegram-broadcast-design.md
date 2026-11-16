# Telegram Broadcast — Design

## Goal

Superadmin can send one plain-text message to several Telegram groups (3 for now) from the PayGrid web dashboard.

## Decisions

- Access: `superadmin` only.
- Groups: configured in `.env`, not editable from the web.
- Content: plain text only, max 4096 characters (Telegram `sendMessage` limit). No `parse_mode`, so special characters never break delivery.
- History: each broadcast is recorded through the existing `AuditLogService`. No new table.
- Delivery: synchronous HTTP call per group. No queue.

## Configuration

```
TELEGRAM_BROADCAST_BOT_TOKEN=123456:ABC...
TELEGRAM_BROADCAST_GROUPS="Grup CS:-1001111111111,Grup MA:-1002222222222,Grup Ops:-1003333333333"
```

`TELEGRAM_BROADCAST_GROUPS` is a comma-separated list of `Name:chat_id`. The bot must be a member of every group.

## Components

- `app/Services/Telegram/TelegramBroadcaster.php`
  - `groups(): array` — parses the config into `[['name' => ..., 'chat_id' => ...], ...]`.
  - `isConfigured(): bool` — token present and at least one group.
  - `send(string $chatId, string $text): bool` — `POST https://api.telegram.org/bot{token}/sendMessage`. Logs and returns `false` on failure.
- `SuperadminController`
  - New page key `broadcast-telegram` (menu label "Broadcast Telegram").
  - New action `sendTelegramBroadcast` on `POST /superadmin/broadcast-telegram` (`throttle:dashboard-writes`).
- `resources/views/paygrid/superadmin.blade.php`
  - Textarea, one checkbox per group (all checked by default), submit button with a confirm prompt.
  - Warning and disabled button when not configured.

## Data flow

1. Superadmin submits `message` and `chat_ids[]`.
2. Validation: `message` required, max 4096; `chat_ids` required, each must be one of the configured chat IDs.
3. The controller calls `send()` once per selected group.
4. The audit log records `telegram.broadcast_sent` with the message and per-group result. The target is the acting user.
5. Redirect back with a flash status listing each group as sent or failed.

## Testing

- Sends only to selected groups, with the expected token URL and payload (`Http::fake`).
- Flash status reports per-group success and failure.
- Audit log entry is written.
- Unknown chat ID is rejected by validation.
- Non-superadmin gets 403.
- Not configured: no HTTP request is made.
