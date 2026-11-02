# Co Farrel

A read-only DevOps agent for the PayGrid Telegram support bot. It answers in one WhatsApp group when someone tags it, using the Claude Agent SDK.

## What it can do (stage 1)

Four tools, all read-only, all with secrets redacted from their output:

| Tool | What it reads |
|---|---|
| `get_bot_status` | `bottele:*` entries in `php artisan schedule:list`, the Telegram `getWebhookInfo` result (url, pending updates, last error), and ticket and attendance counts from the bot database |
| `get_logs` | The last 4 MB of `storage/logs/laravel.log`, filtered by a word (default `bottele`) |
| `read_code`, `search_code` | Files under `app`, `bootstrap`, `config`, `database`, `docs`, `resources`, `routes` and `tests` only |

It cannot edit files, run shell commands, run tests, deploy or change `.env`. Claude Code's built-in tools are switched off, `permissionMode` is `dontAsk`, and server-side Claude Code settings are not loaded (`settingSources: []`).

`.env`, `storage`, `vendor`, keys, `.bak` files and anything outside the app folder are refused before a file is opened. `get_bot_status` reads the bot token from `.env` itself, uses it for the one Telegram request, and never returns it.

## How a message flows

1. WAHA posts every message event to `http://HOST:PORT/waha`, signed with HMAC-SHA512 (`X-Webhook-Hmac`). Unsigned or wrongly signed requests get 401.
2. The agent only answers messages in `WA_GROUP_ID` that tag it (`@<BOT_NUMBER>`, `@<BOT_LID>`, or the words "Co Farrel"), from numbers listed in `MEMBERS`. Each member has a role (pemilik, atasan, anggota) that is passed to the model by the server, not taken from the message.
3. Questions are handled one at a time. Each day starts a new conversation; within a day the agent keeps context.
4. The reply is sent to the group with WAHA `sendText`, starting with the asker's name.

## Install on the server

Run as a dedicated user that can read `/var/www/paygrid` (for example the web server user), not root.

```bash
cd /var/www/paygrid/tools/co-farrel
npm ci
cp .env.example .env    # fill it in; never commit it
npm test                # unit tests for the path guard, redaction, members and mentions
```

systemd unit, `/etc/systemd/system/co-farrel.service`:

```ini
[Unit]
Description=Co Farrel WhatsApp agent
After=network.target

[Service]
User=apache
WorkingDirectory=/var/www/paygrid/tools/co-farrel
EnvironmentFile=/var/www/paygrid/tools/co-farrel/.env
ExecStart=/usr/bin/npx tsx src/server.ts
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now co-farrel
journalctl -u co-farrel -f
```

Then in the WAHA dashboard, add a webhook on the bot's session: URL `http://HOST:PORT/waha`, event `message`, HMAC key = `WAHA_WEBHOOK_HMAC_KEY`. If WAHA runs in Docker, `127.0.0.1` inside the container is not the host: set `HOST` to the Docker bridge address and use that in the webhook URL.

## Check on first use

The WAHA field that holds the sender in a group (`participant` or `author`) and the form of a mention (`@<number>` or `@<lid>`) depend on the WAHA engine. If the agent never answers, look for `ignored message from a number not in MEMBERS` in the journal, and set `BOT_LID` if mentions arrive as a different number.

## Cost

Each answered message is one agent run on `AGENT_MODEL` (default `claude-opus-5-5`), capped at `MAX_BUDGET_USD` (default $1) and 15 turns. The cost of every answer is printed to the journal.
