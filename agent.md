# PayGrid Agent Context

This file is the working context for AI agents or engineers taking over PayGrid. It documents the production setup, code architecture, operational flows, business rules, and known gotchas.

## Project Overview

PayGrid is a Laravel-based payment operations dashboard for monitoring merchant QRIS/topup transactions, syncing data from gateways, managing merchant access, handling CS tickets, and producing finance/MA reports.

The main gateway is Hilogate. Artageto support also exists. The dashboard has role-based portals for superadmin, MA, agent, merchant admin, merchant CS, merchant finance, and center support.

Core goals:

- Keep merchant transaction data synced from Hilogate/Artageto.
- Provide public topup links for CM merchants.
- Provide internal dashboards for checking successful transactions, CS follow-up, tickets, finance, and MA reporting.
- Keep transaction totals consistent and success-only where business rules require.
- Keep export data safe enough for sharing.

## Production / AWS

Production server:

- Host/IP: `15.232.137.74`
- SSH user: `ec2-user`
- App path: `/var/www/paygrid`
- Web server: nginx
- Runtime: PHP/Laravel, MySQL, database queue workers
- Production app path is not a git repository. Deploys have been done by direct file upload over SSH/SFTP.

SSH access pattern:

- Use SSH/SFTP to `ec2-user@15.232.137.74`.
- Do not hardcode the SSH password in this file or in committed scripts.
- If an AI/automation needs direct SSH, pass credentials at runtime using environment variables:
  - `SSH_HOST=15.232.137.74`
  - `SSH_USER=ec2-user`
  - `SSH_PASS=<provide at runtime>`
- Temporary Node scripts can use the project dependency `ssh2` to connect with those environment variables.
- Always delete temporary SSH helper scripts after use.
- Prefer read-only checks first; upload only the exact changed files when deploying.

Example temporary SSH helper shape:

```js
const { Client } = require('ssh2');

const client = new Client();
client.on('ready', () => {
  client.exec('cd /var/www/paygrid && php artisan paygrid:queue-monitor', (error, stream) => {
    if (error) throw error;
    stream.on('data', (data) => process.stdout.write(data));
    stream.stderr.on('data', (data) => process.stderr.write(data));
    stream.on('close', () => client.end());
  });
}).connect({
  host: process.env.SSH_HOST,
  username: process.env.SSH_USER,
  password: process.env.SSH_PASS,
  readyTimeout: 20000,
});
```

Production URLs:

- Dashboard HTTPS: `https://ma-paygrid.hilogate.com`
- Login: `https://ma-paygrid.hilogate.com/login`
- Legacy/public HTTP host: `http://paygrid.15.232.137.74.nip.io`
- Public topup format: `http://paygrid.15.232.137.74.nip.io/topup/{merchant-slug}`

Important production behavior:

- Dashboard traffic should use HTTPS via `ma-paygrid.hilogate.com`.
- Public topup URLs intentionally remain available on legacy HTTP to avoid breaking existing QR/topup links.
- `ma-paygrid.hilogate.com` is behind Cloudflare.
- Use `sudo nginx -t && sudo nginx -s reload` to reload nginx if `systemctl reload nginx` fails due mount namespace issues.
- Never assume `git pull` works on production because `/var/www/paygrid` is not a git repo.

Do not write secrets into this file:

- Do not store SSH passwords.
- Do not store merchant secret keys.
- Do not store `.env` contents.
- If credential checks are needed, query production securely and avoid committing secrets.

## Local Project

Workspace root:

- `C:\paygrid`

Stack:

- PHP `^8.3`
- Laravel `^13.0`
- MySQL in production
- Database queues
- Vite/Tailwind for frontend assets
- Node dependency `ssh2` is available and has been used for temporary SSH/SFTP helper scripts

Useful local commands:

- `php -l path/to/file.php`
- `php artisan test --filter=SomeTestName`
- `php artisan config:clear`
- `php artisan optimize:clear`
- `npm run build`

Test suite note:

- Full `PayGridRoutingTest` has had unrelated failures during some work. For small targeted changes, run specific filters for touched functionality.

## Important Files

Routing and boot:

- `routes/web.php`: all HTTP dashboard, public topup, checklist, ticket routes.
- `routes/api.php`: API routes if present/used.
- `routes/console.php`: scheduler commands, gateway sync commands, maintenance commands.
- `bootstrap/app.php`: scheduler, CSRF exemptions, trusted proxies, middleware aliases.
- `config/paygrid.php`: gateway, topup, sync, security, report configuration.
- `config/queue.php`: database queue config and retry settings.

Controllers:

- `app/Http/Controllers/AuthController.php`: login/logout.
- `app/Http/Controllers/MaController.php`: MA overview/report/fee/agents/stores/create-store/export.
- `app/Http/Controllers/SuperadminController.php`: superadmin pages and global fee/account controls.
- `app/Http/Controllers/DashboardController.php`: agent dashboard, merchant CS dashboard, merchant finance dashboard, legacy/simple views, agent export.
- `app/Http/Controllers/MerchantAdminController.php`: merchant admin portal users/settings/logs/qris/checklist/history.
- `app/Http/Controllers/TopupController.php`: public topup submit/status/QR/regenerate/status JSON.
- `app/Http/Controllers/ChecklistController.php`: checklist mark/unmark and CS note updates.
- `app/Http/Controllers/SupportTicketController.php`: merchant CS ticket creation/submission.
- `app/Http/Controllers/CenterSupportController.php`: CS pusat ticket list/update/attachments.
- `app/Http/Controllers/MerchantRegistrationController.php`: onboarding/topup form entry.
- `app/Http/Controllers/MerchantRegistrationWorkflowController.php`: submit/approve/reject merchant onboarding.
- `app/Http/Controllers/MerchantProvisioningController.php`: retry merchant provisioning.
- `app/Http/Controllers/GatewaySyncRetryController.php`: manual sync retry.
- `app/Http/Controllers/MonitoringController.php`: admin monitoring.

Services:

- `app/Services/TransactionIngestionService.php`: normalizes gateway payloads and upserts `topup_requests`.
- `app/Services/TopupService.php`: creates public topup transactions and handles gateway failures.
- `app/Services/Gateway/HilogateClient.php`: Hilogate API client, signature, QRIS/transaction/settlement pulls.
- `app/Services/Gateway/ArtagetoClient.php`: Artageto API client.
- `app/Services/Gateway/GatewayManager.php`: returns gateway client for merchant.
- `app/Services/Gateway/GatewayClientInterface.php`: gateway client contract.
- `app/Services/Gateway/GatewayCallbackSignatureService.php`: callback signature validation.
- `app/Services/GatewaySyncDispatcher.php`: queue dispatch locks and merchant cooldowns.
- `app/Services/GatewayBalanceService.php`: gateway balance cache.
- `app/Services/MerchantSettlementService.php`: settlement sync.
- `app/Services/MetricRollupService.php`: merchant daily metrics rollup.
- `app/Services/MetricsService.php`: dashboard metrics helper.
- `app/Services/FeeService.php`: fee snapshots/calculation.
- `app/Services/ChecklistService.php`: checklist mark/unmark persistence and audit.
- `app/Services/AuditLogService.php`: writes audit logs.
- `app/Services/Navigation/MenuBuilder.php`: role/merchant navigation menus.

Jobs:

- `app/Jobs/SyncMerchantTransactions.php`: live transaction sync job.
- `app/Jobs/BackfillMerchantTransactions.php`: deeper backfill sync job.
- `app/Jobs/RebuildMerchantDailyMetric.php`: rebuild metrics.
- `app/Jobs/ProvisionMerchantOnGateway.php`: merchant provisioning.

Models:

- `app/Models/Merchant.php`
- `app/Models/Agent.php`
- `app/Models/User.php`
- `app/Models/TopupRequest.php`
- `app/Models/GatewaySyncLog.php`
- `app/Models/MerchantGatewayBalance.php`
- `app/Models/MerchantSettlement.php`
- `app/Models/MerchantDailyMetric.php`
- `app/Models/FeeSnapshot.php`
- `app/Models/FeeScheme.php`
- `app/Models/SupportTicket.php`
- `app/Models/AuditLog.php`
- `app/Models/MerchantRegistration.php`
- `app/Models/AgentOnboardingLink.php`
- `app/Models/PaygridSetting.php`

Views/assets:

- `resources/views/layouts/paygrid.blade.php`: main dashboard layout, global CSS, ops strip, JS include.
- `resources/views/layouts/partial.blade.php`: partial layout for live refresh.
- `resources/views/paygrid/login.blade.php`: normal login form.
- `resources/views/paygrid/ma.blade.php`: MA screens and report export UI.
- `resources/views/paygrid/ma-overview.blade.php`: MA overview panels.
- `resources/views/paygrid/superadmin.blade.php`: superadmin screens.
- `resources/views/paygrid/agent-overview.blade.php`: agent dashboard.
- `resources/views/paygrid/merchant-admin.blade.php`: merchant admin portal.
- `resources/views/paygrid/merchant-cs.blade.php`: merchant CS portal.
- `resources/views/paygrid/merchant-finance.blade.php`: merchant finance portal.
- `resources/views/paygrid/center-support.blade.php`: CS pusat dashboard.
- `resources/views/paygrid/topup.blade.php`: public topup form.
- `resources/views/paygrid/topup-status.blade.php`: public QR/status page.
- `resources/views/paygrid/onboarding-form.blade.php`: onboarding form.
- `resources/views/paygrid/monitoring.blade.php`: monitoring screen.
- `resources/views/paygrid/simple-page.blade.php`: legacy/simple shared page.
- `public/js/paygrid-live.js`: live refresh, scroll preservation, note autosave, partial fetch.

## Data Model Map

Core relations:

- `agents` own many `merchants`.
- `agents.ma_user_id` links an agent/group to a MA user.
- `merchants.agent_id` links a merchant/store to an agent/group.
- `users.merchant_id` links admin/CS/finance/readonly users to one merchant.
- `topup_requests.merchant_id` links transactions to merchant.
- `support_tickets.merchant_id` and `support_tickets.topup_request_id` link tickets to merchant/transaction.
- `gateway_sync_logs.merchant_id` tracks gateway pull activity.
- `merchant_gateway_balances.merchant_id` stores latest gateway balance info.
- `merchant_settlements.merchant_id` stores Hilogate settlement data.
- `merchant_daily_metrics.merchant_id` stores rollup metrics.
- `audit_logs.target_type` and `audit_logs.target_id` track changes to records.

Important tables:

- `merchants`: merchant/store config, gateway credentials, slug, type, topup status, fees, agent mapping.
- `users`: login accounts and roles; `plain_password` may be encrypted for newer rows and may be invalid/plain for older rows.
- `topup_requests`: local transaction source of truth for dashboard rows.
- `gateway_sync_logs`: high-volume logs for gateway sync success/failure.
- `failed_jobs`: Laravel failed queue jobs; transient DB deadlocks are filtered from ops badge.
- `jobs`: database queue table.
- `support_tickets`: CS issue/ticket workflow.
- `merchant_settlements`: settlement report data.
- `audit_logs`: operational action history.

## Roles And Access

Roles:

- `superadmin`: global system/admin access.
- `ma`: MA dashboard across assigned agents/merchants.
- `agent`: agent/group dashboard and onboarding/request flows.
- `admin`: merchant admin portal; can manage merchant users/settings and checklist.
- `readonly_admin`: merchant admin read-only access; cannot manage users/passwords.
- `finance`: merchant finance portal.
- `cs`: merchant CS portal; can update notes, create tickets, and checklist success transactions for own merchant.
- `readonly_cs`: merchant CS read-only access.
- `cs_pusat`: center support ticket dashboard.

Access rules:

- `RoleMiddleware` enforces route roles.
- `MerchantScopeMiddleware` prevents merchant-scoped users from accessing other merchants.
- MA access is scoped through merchant agent's `ma_user_id`.
- Superadmin is global.
- CS/admin scoped users require matching `users.merchant_id`.
- Readonly users should not mutate sensitive data.

## Main Routes

Auth:

- `GET /login`
- `POST /login`
- `POST /logout`

MA:

- `GET /ma`
- `GET /ma/report`
- `GET /ma/report/export`
- `GET /ma/fee`
- `GET /ma/approvals`
- `GET /ma/mapping`
- `GET /ma/stores`
- `GET /ma/agents`
- `GET /ma/create-store`

Superadmin:

- `GET /superadmin`
- `GET /superadmin/{page}`
- `POST /superadmin/ma`
- `POST /superadmin/ma/{user}`
- `POST /superadmin/merchant-fee/{merchant}`
- `POST /superadmin/merchant-group`
- `POST /superadmin/accounts/{user}/reset`

Agent:

- `GET /agent`
- `GET /agent/create-store`
- `POST /agent/onboarding-links`
- `GET /agent/requests`
- `GET /agent/export`

Merchant admin:

- `GET /portal/{merchant}/admin/users`
- `GET /portal/{merchant}/admin/settings`
- `GET /portal/{merchant}/admin/logs`
- `GET /portal/{merchant}/admin/qris`
- `GET /portal/{merchant}/admin/checklist`
- `GET /portal/{merchant}/admin/history`

Merchant CS:

- `GET /portal/{merchant}/cs/tickets`
- `GET /portal/{merchant}/cs/topup`
- `GET /portal/{merchant}/cs/checklist`
- `GET /portal/{merchant}/cs/history`
- `POST /portal/{merchant}/cs/topup/{topupRequest}/ticket`
- `POST /portal/{merchant}/cs/tickets/{ticket}/submit`

Finance:

- `GET /portal/{merchant}/finance/overview`
- `GET /portal/{merchant}/finance/settlement`
- `GET /portal/{merchant}/finance/report`

Public topup:

- `GET /topup/{merchant?}`
- `POST /topup/{merchant}/submit`
- `GET /topup/{merchant}/status/{topupRequest:public_token}`
- `GET /topup/{merchant}/qr/{topupRequest:public_token}`
- `POST /topup/{merchant}/regenerate/{topupRequest:public_token}`
- `GET /api/topup/{merchant}/status/{topupRequest:public_token}`

Checklist/note API:

- `PATCH /api/topup-requests/{topupRequest}/checklist`
- `PATCH /api/topup-requests/{topupRequest}/cs-note`

## Merchant Types

Merchant type matters:

- `cm`: Customer/CM merchants. Usually have public topup enabled and a `/topup/{slug}` URL.
- `script`: Script/API merchants. Usually no public topup link; transactions are pulled from gateway and shown in dashboard/history.

Business rules:

- `topup_enabled=true` only for merchants that should expose public topup flow.
- Script merchants normally have `topup_enabled=false` and `topup_url=null`.
- Sync includes approved active topup CM merchants and approved script merchants.

## Topup Flow

Public topup flow:

1. User opens `http://paygrid.15.232.137.74.nip.io/topup/{merchant-slug}`.
2. Form posts to `/topup/{merchant}/submit`.
3. `TopupController::store` calls `TopupService`.
4. `TopupService` creates QRIS transaction through gateway client, usually Hilogate.
5. Local `TopupRequest` is created with `public_token`.
6. User is redirected to `/topup/{merchant}/status/{public_token}`.
7. QR image is served by `/topup/{merchant}/qr/{public_token}` using local SVG generation.
8. Status polling uses `/api/topup/{merchant}/status/{public_token}`.
9. Gateway callbacks and/or sync update local status.

Public topup security:

- Public status/QR routes use `public_token`, not numeric DB IDs.
- Regenerate route is CSRF-exempt in `bootstrap/app.php` for `topup/*/regenerate/*` so public topup flows work.
- Public topup endpoints are rate limited.

Topup failure handling:

- Gateway exceptions should surface as validation/form errors, not Laravel 500s.
- Regression coverage exists in `tests/Feature/PayGridRoutingTest.php`.

## Gateway Integration

Hilogate client:

- File: `app/Services/Gateway/HilogateClient.php`
- Config: `config/paygrid.php` under `gateway.hilogate`
- Base URL default: `https://app.hilogate.com/api`
- Signature: `md5($path.$bodyString.$secret)` for POST; GET uses empty body string.
- Headers include `X-Merchant-ID`, `X-Signature`, `X-Environment`, `X-Request-ID`.

Hilogate transaction pull modes:

- `transactions`: `/api/v1/transactions`
- `qris`: `/api/v1/merchants/{merchant_id}/qris`

Adaptive endpoint behavior:

- CM merchants try `/api/v1/merchants/{merchant_id}/qris` first, fallback `/api/v1/transactions`.
- Script merchants try `/api/v1/transactions` first, fallback `/api/v1/merchants/{merchant_id}/qris`.
- This logic lives in `SyncMerchantTransactions` and `BackfillMerchantTransactions` around `pullTransactions()`.

Common Hilogate statuses:

- `200`: credentials valid and endpoint responded.
- `401`: invalid auth/credential/secret.
- `403`: forbidden, often access/whitelist/merchant permission.
- `429`: rate limit.
- `500`: Hilogate internal server error or endpoint issue.
- Timeout: Hilogate slow/unresponsive for that endpoint.

Cooldown behavior:

- `401/403`: 30 minutes.
- `429`: 5 minutes.
- Default/`500`: 3 minutes.

Gateway error handling:

- Request exceptions are logged to `gateway_sync_logs` and merchant cooldown is applied.
- HTTP gateway failures should not permanently kill sync workers.

## Gateway Sync Flow

Scheduler:

- Defined in `bootstrap/app.php`.
- Live transaction sync runs every 5 seconds:
  - `gateway:sync-transactions --max-pages=1 --page-size=25 --queue=live`
- Balance sync runs every 30 seconds:
  - `gateway:sync-balances`
- Settlement sync runs every 5 minutes:
  - `gateway:sync-settlements`
- Maintenance prune runs hourly:
  - `paygrid:maintenance-prune`

Console commands:

- `php artisan gateway:sync-transactions --merchant={slug-or-merchant-id} --queue=live`
- `php artisan gateway:sync-transactions --merchant={slug} --from=YYYY-MM-DD --to=YYYY-MM-DD --max-pages=10 --page-size=50 --queue=backfill`
- `php artisan gateway:sync-balances --merchant={slug}`
- `php artisan gateway:sync-settlements --merchant={slug}`
- `php artisan paygrid:queue-monitor`
- `php artisan paygrid:maintenance-prune`
- `php artisan metrics:rebuild-daily {date}`
- `php artisan fees:backfill-snapshots`
- `php artisan gateway:health-hilogate {merchant}`

Sync job:

- Main job: `app/Jobs/SyncMerchantTransactions.php`
- Current timeout: 55 seconds.
- Tries: 2.
- Updates `SyncCursor`, `GatewaySyncLog`, balances, and metrics.
- Uses `TransactionIngestionService` to normalize/upsert transactions.
- Rebuilds metric rollups for affected dates.
- Dispatches backfill if page limit is hit.

Backfill job:

- File: `app/Jobs/BackfillMerchantTransactions.php`
- Timeout is longer than live sync.
- Used for deeper historical pulls.

Dispatcher:

- File: `app/Services/GatewaySyncDispatcher.php`
- Prevents duplicate active merchant syncs.
- Applies cooldown for noisy/failing merchants.

## Transaction Ingestion Rules

File: `app/Services/TransactionIngestionService.php`

Responsibilities:

- Resolve merchant from payload.
- Normalize status, amount, net, fees, references, timestamps.
- Upsert by `gateway_ref_id`.
- Preserve checklist state on re-sync.
- Preserve existing `expires_at` and `succeeded_at` when gateway payload lacks them.
- Trigger metric rollup unless deferred.
- Snapshot fees.

Important preserved fields on sync:

- `is_processed`
- `processed_by_user_id`
- `checked_by_email`
- `checked_by_role`
- `processed_at`

Timestamp rules:

- `submitted_at` should represent gateway created/submitted time.
- `succeeded_at` should represent gateway paid/success/completed/settled time.
- Duration shown in dashboards is `succeeded_at - submitted_at`.

Dashboard/report counting rules:

- Transaction totals and nominal/volume reports should count only `status = success` where business labels say success/volume/settlement.
- Pending/expired counts may still be shown separately.

## Checklist Flow

Routes:

- `PATCH /api/topup-requests/{topupRequest}/checklist`
- `PATCH /api/topup-requests/{topupRequest}/cs-note`

Files:

- `app/Http/Controllers/ChecklistController.php`
- `app/Services/ChecklistService.php`
- `resources/views/paygrid/merchant-cs.blade.php`
- `resources/views/paygrid/merchant-admin.blade.php`

Rules:

- Only success transactions can be checked.
- CS/admin can checklist only their merchant.
- MA can checklist merchants under their assigned agents.
- Superadmin can checklist globally.
- Uncheck is restricted to admin, MA, and superadmin.
- CS notes are editable only before transaction is checked.
- Checked transaction notes become read-only.

Recent checklist UX fix:

- Previously, unchecking still showed flash message `Transaksi berhasil dichecklist`, which confused users.
- Now checking says `Transaksi berhasil dichecklist.`
- Unchecking says `Checklist transaksi berhasil dilepas.`
- Checked checkbox uncheck action has a confirm prompt: `Lepas checklist transaksi ini?`

Known BL77 incident:

- Merchant: `BL77`
- Transaction: local ID `174724`
- Amount: `30.000`
- RRN: `fb5424ed8191`
- Gateway ref: `qris_01a0140e-c199-7801-a573-a52d10f7ab35`
- It was checked, then admin accidentally clicked checked checkbox and unmarked it.
- It was restored to checked with `checked_by_email=admin@bl77.local`.

## CS Ticket Flow

Merchant CS:

1. CS opens `/portal/{merchant}/cs/topup`.
2. For pending/expired/failed/rejected transactions, CS can create a ticket after allowed waiting period.
3. Ticket appears in merchant CS tickets page.
4. CS submits ticket to center support with required image attachment.

Center support:

1. `cs_pusat`, MA, or superadmin opens `/cs-pusat`.
2. Center support reviews submitted tickets.
3. Center support updates status and notes.
4. Attachments are downloaded through controlled route.

Related files:

- `SupportTicketController`
- `CenterSupportController`
- `resources/views/paygrid/merchant-cs.blade.php`
- `resources/views/paygrid/center-support.blade.php`

Ticket timing:

- Setting: `PaygridSetting::value('ticket_pending_minutes', '40')`
- Config fallback also includes `paygrid.topup.ticket_grace_minutes`.

## Finance And Reports

MA report:

- Controller: `MaController`
- View: `resources/views/paygrid/ma.blade.php`
- Export route: `/ma/report/export`
- Export format is CSV with Excel-friendly filename/label.

MA export currently includes:

- `Masuk`
- `Sukses`
- `Durasi`
- `Toko`
- `Agen`
- `Status`
- `Amount`
- `Reference`
- `RRN`
- `Payment ID`
- `Net`
- `Settlement`

MA export intentionally excludes:

- `Sumber TRX`
- Gateway merchant ID
- Merchant secret key

Agent export:

- Route: `/agent/export`
- Controller: `DashboardController::exportAgentReport`
- Excludes `merchant_id`.
- Includes merchant name and aggregate fields.

Security decision:

- RRN/payment references may be included where business requested.
- Merchant IDs should not be exported in shareable reports.
- Source/debug/backend data should not be exported.

Merchant finance:

- Routes under `/portal/{merchant}/finance/*`.
- Shows overview, settlement, and report views.
- Defaults report status to `success`.

Fee display:

- Percent display uses Indonesian comma format like `0,80%`.
- Inputs use dot decimal format like `0.80`.
- Fee schema/math changes were not fully overhauled without business confirmation.

## Live Refresh UI

File: `public/js/paygrid-live.js`

Behavior:

- Periodically fetches current page with headers:
  - `X-Requested-With: XMLHttpRequest`
  - `X-PayGrid-Partial: 1`
- Replaces elements marked with `data-live-region`.
- Preserves scroll position.
- Preserves active input/textarea/select values.
- Pauses refresh when hidden, modal open, table hovered, active form field, or recent user interaction.
- Force refreshes on visibility/focus/pageshow with guard.
- Autosaves CS notes using `data-cs-note` and `data-note-url`.

Important issue pattern:

- If UI seems to revert after an action, check whether live refresh is preserving stale field state or whether database was actually updated.
- For checklist, check `topup_requests.is_processed`, `checked_by_email`, and `audit_logs`.

## Queue And Operations

Production queue:

- Queue driver: database.
- Live queue workers currently intended active: `paygrid-live-queue@1..6`.
- Backfill worker currently intended active: `paygrid-backfill-queue@1`.
- Older live workers `@7..16` were disabled to reduce MySQL queue contention/deadlocks.
- Older backfill workers `@2..3` were disabled to reduce load.

Health command:

```bash
php artisan paygrid:queue-monitor
```

Typical healthy output:

```text
queue_lag_seconds=0
failed_jobs=0
sync_lag_seconds=...
callback_lag_seconds=...
```

Failed jobs note:

- Dashboard `Failed` badge uses recent `failed_jobs` filtering.
- Transient MySQL deadlocks `SQLSTATE[40001]` / `Deadlock found` are treated as queue contention noise and filtered from operational failed count.
- If `failed_jobs > 0`, inspect latest `failed_jobs.exception` before assuming merchant/gateway outage.

Common production checks:

```bash
cd /var/www/paygrid
php artisan paygrid:queue-monitor
php artisan queue:monitor default,live,backfill --max=100
systemctl is-active paygrid-live-queue@1.service paygrid-live-queue@2.service paygrid-live-queue@3.service paygrid-live-queue@4.service paygrid-live-queue@5.service paygrid-live-queue@6.service
curl -ksS -o /dev/null -w 'login=%{http_code},time=%{time_total}\n' https://ma-paygrid.hilogate.com/login
df -h /
```

Maintenance:

- `paygrid:maintenance-prune` prunes high-volume success sync logs, old failed sync logs, old failed jobs, and transient deadlock jobs.
- Journald is limited to about `200M` on production.
- Laravel logs have logrotate daily keep `7`.

Disk:

- Disk was previously high around `93%`.
- Cleanup/optimization reduced it to around `69-70%` with about `2.5GB` free.
- `gateway_sync_logs` can grow quickly and is pruned.
- `jobs.ibd` and `gateway_sync_logs` may require `OPTIMIZE TABLE` after large deletes.

## Deployment Runbook

Because production app path is not a git repo, deploys have used direct SFTP uploads.

Safe deploy pattern:

1. Make minimal local changes.
2. Run local syntax checks:
   - `php -l path/to/file.php`
3. Run targeted tests if applicable:
   - `php artisan test --filter=RelevantTestName`
4. Upload only changed files to `/var/www/paygrid/...`.
5. On production run:
   - `cd /var/www/paygrid && php artisan optimize:clear`
   - `php -l changed/file.php`
6. Verify dashboard:
   - `curl -ksS -o /dev/null -w 'login=%{http_code},time=%{time_total}\n' https://ma-paygrid.hilogate.com/login`
7. Verify queue if sync touched:
   - `php artisan paygrid:queue-monitor`

When editing nginx:

```bash
sudo nginx -t && sudo nginx -s reload
```

Avoid:

- Do not run destructive git commands on production.
- Do not overwrite unrelated files.
- Do not include secrets in commits/docs.
- Do not assume production deploy can use git.

PowerShell/SSH note:

- Remote PHP snippets with `$`, `|`, `<`, `>` and quotes often break through PowerShell quoting.
- Temporary Node `.cjs` scripts using `ssh2` have been reliable for SSH/SFTP.
- Delete temporary helper scripts after use.

## Debugging Runbook

Check if merchant exists:

```bash
php artisan tinker
App\Models\Merchant::query()->where('slug', 'merchant-slug')->first();
```

Check sync logs for a merchant:

```php
$m = App\Models\Merchant::where('slug', 'merchant-slug')->firstOrFail();
App\Models\GatewaySyncLog::where('merchant_id', $m->id)->latest('finished_at')->limit(10)->get();
```

Check transaction counts:

```php
$m = App\Models\Merchant::where('slug', 'merchant-slug')->firstOrFail();
App\Models\TopupRequest::where('merchant_id', $m->id)->count();
App\Models\TopupRequest::where('merchant_id', $m->id)->selectRaw('status, count(*) c')->groupBy('status')->get();
```

Check a specific transaction by RRN/gateway ref:

```php
App\Models\TopupRequest::query()
    ->where('rrn', 'like', '%partial%')
    ->orWhere('gateway_ref_id', 'like', '%partial%')
    ->orWhere('payment_id', 'like', '%partial%')
    ->latest('submitted_at')
    ->get();
```

Check checklist state:

```php
$t = App\Models\TopupRequest::find($id);
$t->only(['status', 'is_processed', 'checked_by_email', 'checked_by_role', 'processed_at', 'cs_note']);
```

Check audit log for transaction:

```php
App\Models\AuditLog::query()
    ->where('target_type', App\Models\TopupRequest::class)
    ->where('target_id', (string) $id)
    ->latest('created_at')
    ->get();
```

Check raw Hilogate behavior:

- Use `GatewayManager` client first where possible.
- For raw direct HTTP, reproduce Hilogate headers exactly from `HilogateClient`.
- Compare `transactions` vs `qris` pull mode.

If transactions do not appear:

1. Verify merchant exists and is approved.
2. Verify `merchant_id` and `merchant_key` are set.
3. Verify merchant type and topup setting.
4. Run `gateway:sync-transactions --merchant={slug} --queue=live`.
5. Check `gateway_sync_logs` for HTTP status.
6. If HTTP `200` but data is empty, raw endpoint may genuinely have no transactions.
7. If `/transactions` empty and `/qris` errors, ask Hilogate which endpoint should be used for that merchant.

If dashboard `Failed` > 0:

1. Run `php artisan paygrid:queue-monitor`.
2. Inspect `failed_jobs` recent exceptions.
3. Distinguish gateway HTTP failures from database queue deadlocks/timeouts.
4. Queue lag near 0 with isolated failed jobs usually means sync is not globally stuck.

If checklist says successful but UI does not change:

1. Find transaction by RRN/ref.
2. Check `is_processed` and `checked_by_email`.
3. Check audit log for `topup.checklist_marked` and `topup.checklist_unmarked`.
4. Confirm user did not accidentally uncheck a checked row.
5. Check live refresh and browser cache if DB is correct.

## Security Rules

Do not expose:

- Merchant secret keys.
- `.env` values.
- SSH credentials.
- Raw backend/debug responses to end users.
- Gateway merchant IDs in shareable exports unless explicitly approved.

Export policy:

- MA export excludes `Sumber TRX`.
- Agent export excludes `merchant_id`.
- RRN/payment references are allowed in MA export per business direction.
- Merchant secret keys are never exported.

Public topup safety:

- Use `public_token` for status/QR URLs.
- Do not expose numeric `topup_requests.id` in public topup status links.

Login/security:

- Login form is normal and visible immediately.
- Visible `PayGrid/PAYGRID` branding was removed from login form.
- Trusted proxies are enabled for Cloudflare/nginx proxy handling.
- Security headers middleware is appended globally.
- CSP upgrades insecure requests on HTTPS.

## Current Production Notes

General:

- Login masking/status page was removed; `/login` shows normal login immediately.
- Public topup HTTP flows are intentionally preserved.
- Dashboard HTTP legacy redirects to HTTPS except public topup/callback routes.
- User prefers compact tables with no horizontal scrolling.

Known merchant/access statuses:

- `Sari Indah 88`: current sync returns `200`; no current IP whitelist issue. Old `401` existed on `2026-08-14`, likely credential/auth at the time.
- `A99B | Nexus`: slug `a99b-nexus`, script merchant, agent `AG-EPC`, topup disabled, sync credential updated and later returned `200`; local transaction count was `0` at last check.
- `A99B | CM`: slug `a99b-cm`, CM merchant, agent `AG-EPC`, topup enabled, sync returned `200`; local transaction count was `0` at last check because merchant had just activated.
- `BL77`: checklist incident fixed as described above.
- `oc Liga Pools`: known Hilogate `403` historically.
- `Tiktok5000`: known Hilogate `403` historically.
- Old `A99` before rename had Hilogate `500` issues; credential/merchant info was later replaced for `A99B | Nexus`.

Recent production merchant additions/updates:

- `A99B | Nexus`
  - Type: `script`
  - Slug: `a99b-nexus`
  - Agent: `AG-EPC`
  - Topup: disabled
- `A99B | CM`
  - Type: `cm`
  - Slug: `a99b-cm`
  - Agent: `AG-EPC`
  - Topup: enabled
  - Topup URL: `http://paygrid.15.232.137.74.nip.io/topup/a99b-cm`
- `MalingBet`
  - Type: `script`
  - Slug: `malingbet`
  - Topup: disabled

Recent production accounts added:

- Several merchant admin accounts were created for merchants like Rajakhodam89, HiuBet88, GedekBet, Singobet, Valohoki.
- A99B CM and Nexus finance/CS accounts were created.
- For exact user/password exports, see generated local file `paygrid-access-directory-full.xls` if present. Treat it as sensitive.

Generated sensitive local files:

- `paygrid-access-directory-full.xls` may exist in workspace.
- It contains all merchant/user access rows and passwords available in DB.
- Keep it internal and do not commit/share broadly.

## Business Rules

Counting/reporting:

- Dashboard success volume and report totals count `status = success` only.
- Pending/expired are displayed separately and should not inflate success volume.
- Finance report default status is `success`.

Merchant/topup:

- CM merchants may have public topup links.
- Script merchants usually do not have public topup links.
- Existing public topup links must not break.

Timing:

- `submitted_at` is gateway created/submitted time.
- `succeeded_at` is gateway paid/success/completed/settled time.
- Duration label comes from `TopupRequest::successDurationLabel()`.

Checklist:

- Success rows can be checked.
- Checked rows show who checked them.
- CS note becomes read-only once checked.
- Admin/MA/superadmin can uncheck; CS cannot uncheck.

Fees:

- Fee display uses two decimals.
- Display format: `x,xx%`.
- Input format: `x.xx`.
- Future rates may differ per merchant; avoid global fee schema changes without business confirmation.

## Common Tasks

Add/update merchant directly in production:

1. Check if merchant exists by slug/name/merchant_id.
2. Check agent exists by code/name.
3. Use DB transaction.
4. Set fields:
   - `name`
   - `slug`
   - `gateway`
   - `merchant_id`
   - `merchant_key`
   - `merchant_type`
   - `topup_enabled`
   - `topup_url`
   - `agent_id`
   - `approval_status`
5. Create/update scoped users with role and merchant ID.
6. Clear cache.
7. Dispatch sync and check `gateway_sync_logs`.

Add user:

- Use `users.email` as login if `username` is blank.
- `plain_password` is hidden and cast encrypted in model, but old rows may be inconsistent.
- Always hash `password` with Laravel Hash.
- Link merchant-scoped roles to `merchant_id`.

Generate access directory:

- Pull merchants and users from production.
- Include dashboard/topup URLs, roles, usernames/emails, and passwords if explicitly requested.
- Do not include gateway merchant IDs or secret keys unless explicitly required and approved.

Patch production file:

- Upload changed file to matching path under `/var/www/paygrid`.
- Run `php artisan optimize:clear`.
- Run `php -l` for changed PHP files.
- Verify `/login` returns `200`.

## Known Gotchas

- Production app is not git-controlled; direct file uploads are normal for now.
- PowerShell quoting often breaks inline remote PHP. Prefer temporary Node `ssh2` helper scripts and delete them after use.
- Some older users have `plain_password` stored in formats that can fail encrypted casts. Use `getRawOriginal('plain_password')` carefully for internal export scripts.
- Database queue can deadlock under too many workers. Keep live workers conservative.
- Hilogate `200` with empty `data` means credentials are valid but no rows returned for that endpoint/filter.
- Hilogate `/qris` can return `500` while `/transactions` returns `200`; endpoint choice depends on merchant type.
- The ops strip caches for a few seconds; immediate dashboard values may lag slightly.
- Live refresh can make UI look like it changed/reverted. Always check DB/audit for true state.
- Do not use destructive git commands like `git reset --hard` unless explicitly approved.

## Current Useful Commands

Production health:

```bash
cd /var/www/paygrid
php artisan paygrid:queue-monitor
php artisan queue:monitor default,live,backfill --max=100
curl -ksS -o /dev/null -w 'login=%{http_code},time=%{time_total}\n' https://ma-paygrid.hilogate.com/login
df -h /
```

Sync one merchant:

```bash
cd /var/www/paygrid
php artisan gateway:sync-transactions --merchant=a99b-cm --queue=live
php artisan gateway:sync-transactions --merchant=a99b-nexus --queue=live
```

Check balance/settlements:

```bash
cd /var/www/paygrid
php artisan gateway:sync-balances --merchant=merchant-slug
php artisan gateway:sync-settlements --merchant=merchant-slug
```

Maintenance:

```bash
cd /var/www/paygrid
php artisan optimize:clear
php artisan paygrid:maintenance-prune
php artisan metrics:rebuild-daily $(date +%F)
```

Nginx reload:

```bash
sudo nginx -t && sudo nginx -s reload
```

Local validation:

```bash
php -l app/Http/Controllers/ChecklistController.php
php artisan test --filter=test_checklist_is_persisted_to_database
npm run build
```

## Handover Summary

If another AI starts here, it should first:

1. Read this file.
2. Inspect relevant code files before editing.
3. Confirm whether the task is local-only or requires production deploy.
4. Use minimal patches.
5. Avoid exposing secrets.
6. Run targeted validation.
7. If deploying, upload only intended files and run production cache clear/syntax/health checks.
