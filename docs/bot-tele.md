# Bot_Tele: Telegram Support Bot

Bot_Tele is the merchant support bot on Telegram. Merchants open tickets from their group with `/support`, and technicians handle the tickets from the tech group. It used to run as the n8n workflow "Merchant Support Bot" (206 nodes, Google Sheets as storage). It now lives inside PayGrid.

The n8n export (`Merchant Support Bot - Create User Multi Email Multi Role Koma.json`) is the source of truth for the port. Each method in `app/Services/BotTele` names the n8n nodes it replaces.

## Architecture

```
Telegram ──POST /api/bottele/webhook──▶ BotTele\WebhookController   (checks secret token)
                                         └▶ BotTeleDispatcher        (Event Router / Message Router / Switch)
                                              ├▶ SupportFlow         menu, category, description, Create User, ticket creation
                                              ├▶ TicketFlow          take, progress, update, resolve, failed, PIC takeover, IP approval
                                              └▶ StaffFlow           absen check-in, /checkticket
scheduler ─bottele:check-* (every minute)──▶ AutomationFlow  reminders, auto-assign, escalation, PIC expiry, attendance expiry
WAHA ──POST /api/bottele/webhooks/waha-incoming──▶ WahaWebhookController → IpWhitelistBridge (web IP Whitelist poll votes)
local dev ─bottele:poll──▶ BotTeleDispatcher                 getUpdates instead of the webhook
                                         TelegramClient              Bot API calls (best-effort)
                                         TicketCards                 every message text and keyboard
```

Bot_Tele uses its **own MySQL server** through the `bottele` connection (`config/database.php`, `BOTTELE_DB_*`). It has three tables: `bot_tickets`, `bot_sessions` and `bot_attendances`. Their migrations live in `database/migrations/bottele` and call `Schema::connection('bottele')`.

The bot tables are kept out of PayGrid's main DB and out of `merchant_tickets` for three reasons:

- The schema follows the old Google Sheet column-for-column: 40 ticket columns, Telegram message ids, SLA fields, and approval fields. Little of that overlaps with `merchant_tickets`.
- Keeping the sheet's shape lets the history be imported 1:1.
- The bot DB can be taken down or moved without touching payments.

Because the migrations sit in a subfolder, the normal `php artisan migrate` never touches the bot DB and keeps working where `BOTTELE_DB_*` is not set.

All bot timestamps are stored as **Asia/Jakarta wall-clock** `Y-m-d H:i:s`, the same as the sheet, even though the app runs in UTC. Always read and write them through `App\Services\BotTele\BotTime`.

## Configuration

| Env | Purpose |
|---|---|
| `BOTTELE_DB_HOST`, `BOTTELE_DB_PORT`, `BOTTELE_DB_DATABASE`, `BOTTELE_DB_USERNAME`, `BOTTELE_DB_PASSWORD` | The separate MySQL server |
| `BOTTELE_TELEGRAM_BOT_TOKEN` | Bot token from @BotFather |
| `BOTTELE_TELEGRAM_WEBHOOK_SECRET` | Must equal the `secret_token` passed to `setWebhook`. While this is empty, every webhook request is rejected with 403. |
| `BOTTELE_ADMIN_CHAT_ID` | Group where `/checkticket` works (n8n: `-1004445566939`) |
| `BOTTELE_TECH_CHAT_ID` | Group that receives the ticket cards (n8n: the same `-1004445566939`) |
| `BOTTELE_IP_APPROVAL_CHAT_ID` | Group that approves or rejects IP Whitelist requests (n8n: `-1004436542388`) |
| `BOTTELE_PIC_ESCALATION_CHAT_ID` | Private chat of the PIC who receives escalations (n8n hardcoded one person's id). Escalation does nothing while this is empty. |
| `BOTTELE_AUTOMATION_SINCE` | Optional. The scheduled ticket jobs skip tickets created before this time. |
| `BOTTELE_WAHA_BASE_URL`, `BOTTELE_WAHA_API_KEY`, `BOTTELE_WAHA_SESSION` | WAHA server. Each falls back to `PAYGRID_WHATSAPP_*`. |
| `BOTTELE_WAHA_IP_APPROVAL_GROUP_ID` | WhatsApp group for the web IP Whitelist poll. No poll is sent while this is empty. |
| `BOTTELE_WAHA_WEBHOOK_HMAC_KEY` | HMAC key set on the WAHA webhook. Unsigned events are rejected. |

No token or chat id is hardcoded in the code.

## Categories and states

| Menu button (`callback_data`) | Ticket category | Session state while waiting for text | Initial ticket status |
|---|---|---|---|
| `ticket_dashboard` | Merchant Details \| User Role | `WAITING_DASHBOARD_DESCRIPTION` | `OPEN` |
| `ticket_api` | Technical Issue | `WAITING_API_DESCRIPTION` | `OPEN` |
| `ticket_whitelist` | IP Whitelist / VPN | `WAITING_WHITELIST_DESCRIPTION` | `WAITING_APPROVAL` |
| `ticket_other` | Others | `WAITING_OTHER_DESCRIPTION` | `OPEN` |
| `ticket_create_user` | Create User | `WAITING_CREATE_USER_DETAILS` | `OPEN` (only after the 4-line format is valid) |

Other session states:

- `SUPPORT_MENU`: the menu was sent.
- `WAITING_CS_UPDATE`: the technician is about to type an update.
- `WAITING_FAILED_REASON`: the technician is about to type the reason a ticket failed.
- `WAITING_IP_REJECTION_NOTE`: the approver is about to type the rejection reason.

Sessions are keyed by `(user_id, chat_id)`.

Ticket status flow:

```
OPEN ─take─▶ ASSIGNED ─Start Investigation─▶ ON_PROGRESS ─Resolve─▶ RESOLVED
                                                        └─Failed + reason─▶ FAILED
WAITING_APPROVAL ─Approve─▶ OPEN (then the normal flow)
                 └─Reject + note─▶ REJECTED
ASSIGNED (AUTO, escalated, not started) ─picprogress─▶ ON_PROGRESS (taken over by the PIC)
```

SLA fields are computed on Resolve and on Failed. **The `*_minutes` columns hold seconds.** n8n changed the unit but kept the column names.

- `pickup_minutes` runs from created to assigned.
- `investigation_lag_minutes` runs from assigned to started.
- `handling_minutes` runs from started to completed.
- `total_resolution_minutes` runs from created to completed.

## Differences from n8n

Each item below was decided on purpose. Check them before cutover.

1. **Fixed: the CS Update edit never reached the merchant.** n8n sent the merchant card edit to the tech chat id, so it always failed and stopped the rest of the chain. The edit now goes to the merchant chat.
2. **Fixed: CS Update never saved `last_update`.** n8n matched the row with `" HGT-…"`, which has a leading space, so the update never matched a row.
3. **Fixed: FAILED wrote `completed_at` into `total_resolution_minutes`.** The computed seconds are stored instead. The importer turns the old bad values into NULL.
4. **Fixed: SLA timezone.** n8n parsed the sheet's Jakarta times in the server's timezone. That is where historical values like `-25141` (about -7 hours) came from. Times are now parsed as Asia/Jakarta.
5. **Kept: Start Investigation rewrites `assigned_at`.** As a result, `investigation_lag_minutes` is about 0 and `pickup_minutes` runs up to the moment investigation starts. This might be a bug. It is kept so new numbers stay comparable with history. To change it, remove `assigned_at` from `TicketFlow::startInvestigation`.
6. **Dead paths are not ported.** `WAITING_RESOLUTION` and `WAITING_IP_APPROVAL_NOTE` (and the "type a resolution or approval note" prompts) were disconnected in n8n: Resolve and Approve act immediately. Sessions left in those states are ignored.
7. **Telegram failures do not stop the flow.** In n8n, one failed Telegram node stopped everything after it, except the four "Delete Support *" nodes. Here every Telegram call is logged and the flow continues, so the database always moves forward.
8. **Take is atomic.** A conditional `UPDATE … WHERE status = 'OPEN'` means two technicians clicking at the same moment cannot both get the ticket.
9. **The webhook checks `X-Telegram-Bot-Api-Secret-Token`.** n8n had no such check.

These behaviours are kept as they were in n8n:

- A photo without a caption is ignored.
- Photos are forwarded only for non-IP-Whitelist tickets.
- Callback queries are not answered.
- `/checkticket` sends nothing when every OPEN ticket is already assigned.
- In Create User, "User Email" is reported missing only when no email token exists at all. An invalid token still counts, and it is reported separately as `Email tidak valid: …`.

## Cutover (manual, not done yet)

n8n keeps running unchanged until the switch.

1. Fill in the `BOTTELE_*` values in the production `.env`, then run `php artisan config:clear`. The scheduler must already be running (`schedule:run` from cron).
   Then deactivate the four scheduled n8n workflows together with the main one. Otherwise n8n and Laravel would both send reminders.
2. Create the tables, then add `merchant_tickets.bot_ticket_id` to the main DB:
   ```
   php artisan migrate --database=bottele --path=database/migrations/bottele --force
   php artisan migrate --force
   ```
3. Import the history (see below). Run the dry-run first.
4. In n8n, **deactivate** the "Merchant Support Bot" workflow. A Telegram bot can have only one webhook, so the Telegram Trigger and Laravel cannot both receive updates.
5. Point the bot at Laravel:
   ```
   curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook" \
     -d url=https://ma-paygrid.hilogate.com/api/bottele/webhook \
     -d secret_token=<BOTTELE_TELEGRAM_WEBHOOK_SECRET> \
     -d 'allowed_updates=["message","callback_query"]'
   curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
   ```
6. Test the bot: `/support` in a merchant group, `absen` in a private chat, and `/checkticket` in the admin group.

To roll back, deactivate the Laravel webhook (`deleteWebhook`) and reactivate the n8n workflow. n8n sets its own webhook when activated.

## Importing the history

```
php artisan bottele:import-sheets --file=Bot_Tele.xlsx            # dry-run, writes nothing
php artisan bottele:import-sheets --file=Bot_Tele.xlsx --commit   # asks for confirmation, then writes
```

The importer:

- Reads the sheets `Tickets`, `session` and `attendance`, matching columns by header name.
- Upserts by `ticket_id`, `(user_id, chat_id)` and `user_id`. Re-running it updates rows in place.
- Skips blank rows and rows without a key. The attendance sheet has many fully blank rows.
- Keeps only the newest session row when n8n appended duplicates, like "Pick Latest * Session" did.
- Never fails on dirty cells. A non-numeric number or an unparseable date becomes NULL and is listed as a warning.

At the end it prints, for each sheet, the number of rows, created/updated rows, skipped rows and errors.

The 2026-10-23 export was checked with a dry run on a local scratch DB:

- The sheets contain 1,400 / 1,041 / 1,015 rows, but only **316 tickets, 5 sessions and 1 attendance row** hold data. The rest are blank rows.
- Two rows (275 and 276) are WhatsApp-era IP Whitelist rows with no `ticket_id`. They are skipped with a warning.
- `approval_group_message_id` sometimes holds a WhatsApp message id instead of a number. Those cells become NULL.
- Historical statuses also include `CLOSED`, which this bot never sets. The value is kept as is.

## Scheduled jobs: reminders, auto-assign, escalation, expiry

These four commands are ported 1:1 from the n8n Schedule Trigger workflows (`remind_tiket_mnt_v2`, `Auto_Assign_Escalation`, `PIC_Ticket_Expiry`, `absen_expired`). Code: `AutomationFlow`.

Each one runs `everyMinute()` from the scheduler (`bootstrap/app.php`), like the n8n "minutes" interval, and does nothing until the bot is configured. Minutes are whole minutes, rounded down, in Asia/Jakarta time.

| Command | n8n workflow |
|---|---|
| `bottele:check-open-ticket-reminders` | remind_tiket_mnt_v2 (reminders and auto-assign share one run, as in n8n) |
| `bottele:check-auto-assign-escalation` | Auto_Assign_Escalation |
| `bottele:check-pic-expiry` | PIC_Ticket_Expiry |
| `bottele:check-attendance-expiry` | absen_expired |

The full escalation path for one ticket:

```
Ticket OPEN, not taken (no assigned_user_id)
  ├─ minute 10 / 20 / 30 since created_at: "REMINDER 1/3", "REMINDER 2/3", "FINAL REMINDER 3/3"
  │     each one deletes the previous card (cs_group_message_id) and posts the new one with "Take Ticket"
  └─ a later run, once reminder 3 was sent (count >= 3, waiting >= 30):
        auto-assign to a RANDOM technician who is HADIR, not expired and has no active_ticket_id
        (nobody available: retried every minute)
        → private card with "Start Investigation"; master card in the tech group → 🟡 "Assignment : Auto"

Ticket ASSIGNED, assignment_type=AUTO, Start Investigation not clicked
  └─ minute 10 since auto_assigned_at: escalate to the PIC (BOTTELE_PIC_ESCALATION_CHAT_ID)
        → the tech's private card is deleted; the PIC gets 🟠 + "Start Investigation" (picprogress_…);
          master card → 🟠 "Previous PIC"

Ticket escalated, the PIC did not click either
  └─ minute 10 since escalated_at: status EXPIRED (no automatic reassignment);
        the original technician's active_ticket_id is released

Attendance HADIR whose expires_at (check-in + 8 h) has passed
  └─ "MASA KEHADIRAN BERAKHIR" sent once → TIDAK_HADIR, expiry_notified=true
```

Until the PIC clicks `picprogress_`, `assigned_user_id` still points at the original technician. The click runs the ported PIC takeover, which hands the ticket to the PIC.

Decisions and deviations in these jobs:

- **Kept on purpose (decided 2026-10-24):** the `last_update` texts say "after 30 minutes" for both escalation and expiry, but the real wait is 10 minutes. They stay verbatim, as in n8n, so they match history. Do not "fix" them without a new decision.
- **Fixed: the master card after auto-assign.** n8n deleted every reminder card first, including the one `cs_group_message_id` points at, so its "Edit Master" call always failed and the tech group lost the card. Now the master card is edited and kept, and only the other reminder cards are deleted.
- `BOTTELE_AUTOMATION_SINCE` (optional, Jakarta time) makes the ticket jobs (reminders, auto-assign, escalation, PIC expiry) skip older tickets. Leave it empty in production so open historical tickets keep being handled, as n8n did. Set it locally so the 49 imported OPEN tickets do not flood the test group.
- `absen.json` is not ported separately. Its check-in logic is the same as the main workflow's `absen` route. Its reply text ("✅ ABSEN BERHASIL…") differs from the main workflow's card, and this port uses the main workflow's card. Both workflows have a Telegram Trigger, and a bot can have only one webhook, so only one of them can really receive traffic. Check in n8n which one is live.

## IP Whitelist approval: two channels

| Ticket comes from | Approval | Then |
|---|---|---|
| Telegram bot (`ticket_whitelist`) | **Approve/Reject buttons** in `BOTTELE_IP_APPROVAL_CHAT_ID` (`TicketFlow`) | Approve → OPEN + "Take Ticket" in the tech group |
| PayGrid web form (`merchant_tickets`, `ip_whitelist`) | **WhatsApp poll** (WAHA), or the dashboard Approve/Reject that already existed (`MaWaTicketController`) | Approve → the same "Take Ticket" push (`TicketFlow::pushApprovedToTech`) |

The web path (`IpWhitelistBridge`, port of "PayGrid - IP Whitelist WA Approval"):

1. `MerchantTicketService::create()` dispatches `SendIpWhitelistWaPoll` for a new `ip_whitelist` ticket, but only once WAHA is configured (`BOTTELE_WAHA_*`). The existing `NotifyWaTicketLink` notification still runs as before.
2. The job sends the info text and an Approve/Reject poll to `BOTTELE_WAHA_IP_APPROVAL_GROUP_ID`. It then creates a **new** `bot_tickets` row: a new `HGT-` id, `WAITING_APPROVAL`, and the poll id in `approval_group_message_id`. The two tickets are linked through `merchant_tickets.bot_ticket_id`.
3. WAHA posts the vote to `POST /api/bottele/webhooks/waha-incoming`. The request must carry `X-Webhook-Hmac` (HMAC-SHA512 of the body with `BOTTELE_WAHA_WEBHOOK_HMAC_KEY`). While no key is set, every request is rejected. The vote updates the bot ticket and the web ticket (`MerchantTicketService::applyExternalApproval`), and an approval also pushes "Take Ticket".
4. Whichever comes first, the poll or the dashboard button, wins. A dashboard decision is mirrored onto the bot ticket (`syncFromDashboard`), and later votes return `already_decided`.

Differences from n8n:

- A rejection is also written back to the web ticket. n8n only called back on approval.
- A retracted or unknown vote option is ignored. n8n treated anything other than "Approve" as Reject.
- The voter name is looked up by the voter's id. n8n passed the display name as `contactId`.
- `/api/n8n/tickets/{no}/approval` still exists and is marked `@deprecated`. Only the inactive n8n workflow called it. Remove it once nothing else is confirmed to use it.

**TODO: verify WAHA in production.** The n8n workflow was never activated, so these payload paths are unconfirmed guesses (the same ones n8n used):

- the poll id in the `sendPoll` response (`id._serialized` / `id.id` / `id`);
- the `poll.vote` fields (`payload.poll.id`, `payload.vote.selectedOptions[0]`, `payload.vote.from`);
- the HMAC header name.

Anything unrecognised is logged in full as `bottele.waha_unrecognized_poll_response`, `bottele.waha_unrecognized_poll_vote` or `bottele.waha_webhook_rejected`. Check `storage/logs` after the first real poll, then adjust the paths.

## Local testing (no public URL)

Telegram cannot deliver a webhook to `localhost`. Locally, use a **separate test bot** and polling. The production bot stays with n8n.

1. Create the test bot in @BotFather. Run `/setprivacy` → **Disable**, so the bot can read plain-text descriptions in groups. Then add the bot to a test merchant group, a test tech group and a test approval group.
2. Fill in `.env`:
   - `BOTTELE_TELEGRAM_BOT_TOKEN` = the **test bot's** token;
   - `BOTTELE_ADMIN_CHAT_ID`, `BOTTELE_TECH_CHAT_ID`, `BOTTELE_IP_APPROVAL_CHAT_ID` = the test groups' chat ids.
3. Create the local DB and data (MySQL from XAMPP):
   ```
   mysql -u root -e "CREATE DATABASE bottele CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   php artisan migrate --database=bottele --path=database/migrations/bottele
   php artisan bottele:import-sheets --file="C:/Users/farel/Downloads/Bot Tele.xlsx" --commit
   ```
4. Run two terminals:
   ```
   php artisan bottele:poll        # receives updates (replaces the webhook)
   # the four timers only; schedule:work would also run every other PayGrid job (gateway sync, ...)
   while true; do for c in check-open-ticket-reminders check-auto-assign-escalation check-pic-expiry check-attendance-expiry; do php artisan bottele:$c; done; sleep 60; done
   ```

While the bot has a webhook set, `bottele:poll` prints a "getUpdates failed" warning and keeps retrying. Telegram does not allow getUpdates and a webhook at the same time.
