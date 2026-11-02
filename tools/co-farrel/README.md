# Co Farrel

A DevOps agent for the PayGrid Telegram support bot, built on the Claude Agent SDK. It answers in one WhatsApp group when someone tags it. It only reads on its own; anything that changes the server runs only after the owner (Farrel) approves it from his own WhatsApp.

## Flow

1. A member tags the bot in the group, for example "@Co Farrel report kok gagal terkirim?".
2. The agent **investigates read-only** and answers in the group with what it found.
3. If something must be changed, it files a proposal (summary, step-by-step plan, risk). The group sees "⏳ Menunggu persetujuan Farrel (kode AB7K)", and Farrel gets the full proposal in a private chat.
4. Farrel replies privately with `approve AB7K` (optionally with a note, e.g. `approve AB7K jangan restart php-fpm`) or `tolak AB7K <alasan>`. A proposal can only be decided once and expires after 24 hours.
5. On approve, the agent runs the approved plan in the PayGrid folder, then reports the result in the group to the person who asked, and to Farrel. On reject, the requester is told why.

## What the agent can use

**While investigating** (every message): four read-only tools plus `propose_action`. Claude Code's built-in tools (Bash, Edit, Read, ...) are switched off, and `permissionMode: "dontAsk"` denies anything not pre-approved.

| Tool | What it reads |
|---|---|
| `get_bot_status` | `bottele:*` entries in `php artisan schedule:list`, the Telegram webhook state (url, pending updates, last error), and ticket and attendance counts from the bot database |
| `get_logs` | The last 4 MB of `storage/logs/laravel.log`, filtered by a word (default `bottele`) |
| `read_code`, `search_code` | Files under `app`, `bootstrap`, `config`, `database`, `docs`, `resources`, `routes` and `tests` only |

`.env`, `storage`, `vendor`, keys, `.bak` files and anything outside the app folder are refused before a file is opened. `get_bot_status` reads the bot token from `.env` itself, uses it for the one Telegram request, and never returns it. Every tool output and every reply is redacted for tokens and keys.

**While running an approved plan**: Bash, Read, Edit, Write, Glob and Grep in the PayGrid folder, with these limits:
- The prompt contains only the approved plan; the agent must stop and report if it needs anything else.
- Files are copied to `~/co-farrel-backups/<KODE>/` before they are changed.
- Always denied: `sudo`, `su`, `rm -rf`, shutdown/reboot, reading or editing `.env`, `printenv`/`env`, `git push`, `curl`/`wget`, and destructive migrations (`migrate:fresh`, `migrate:reset`, `db:wipe`). These rules are best effort, not a sandbox: the real limit is the Unix user the service runs as.
- One run at a time, capped at `MAX_EXECUTE_BUDGET_USD` (default $5) and 40 turns.
- Every tool call is written to the journal (`journalctl -u co-farrel`).

Settings and `CLAUDE.md` files on the server are not loaded (`settingSources: []`), so the agent follows only its own prompts.

## Install on the server

Run as a dedicated, non-root user that can read and write `/var/www/paygrid` (for example the web server user). That user's permissions are the hard limit on what an approved run can do.

```bash
cd /var/www/paygrid/tools/co-farrel
npm ci
cp .env.example .env    # fill it in; never commit it
npm test
```

### AI login (Anthropic Console account)

Log in once as the service user, with an Anthropic Console account (platform.claude.com), not a claude.ai Pro/Max plan: Anthropic does not allow claude.ai login for agents built on the Agent SDK.

```bash
sudo -u apache -H npx claude      # then /login and choose the Anthropic Console account, open the link on your laptop
```

Leave `ANTHROPIC_API_KEY` unset; it would take precedence over the login. On the first message, check the journal: an authentication error means the login was not picked up, and an API key from the same Console account in `.env` is the fallback.

### Service

`/etc/systemd/system/co-farrel.service`:

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

### WAHA webhook

On the bot's WAHA session, add a webhook: URL `http://HOST:PORT/waha`, event `message`, HMAC key = `WAHA_WEBHOOK_HMAC_KEY`. Requests without a valid `X-Webhook-Hmac` get 401. If WAHA runs in Docker, `127.0.0.1` inside the container is not the host: set `HOST` to the Docker bridge address and use that in the webhook URL.

Use a separate WhatsApp number/session for the bot: messages the bot's own number sends are ignored, so it cannot be the owner's number.

## Check on first use

- The field that holds the group sender (`participant` or `author`) and the form of a mention (`@<number>` or `@<lid>`) depend on the WAHA engine. If the agent never answers, look for `ignored message from a number not in MEMBERS` in the journal, and set `BOT_LID` if mentions arrive as a different number.
- If the owner's private chat arrives as an `@lid` id instead of `<number>@c.us`, approvals are ignored (the safe failure). Check the journal and adjust before relying on approvals.

## Files

- `requests.json`: every proposal and its decision (last 200), the approval audit trail.
- `state.json`: today's conversation id, so the agent keeps context within a day.
