# PayGrid — Claude Code Context

This project uses the Andrej Karpathy coding guidelines from:
https://github.com/multica-ai/andrej-karpathy-skills

Claude Code should follow these rules when handling PayGrid tasks.

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.


## PayGrid project rules

- Treat this as a real project; do not add dummy/sample data unless explicitly asked.
- Prefer small, surgical changes that directly satisfy the requested task.
- Before changing behavior, inspect the existing architecture and tests.
- Run the narrowest relevant tests after edits, then broader tests when practical.
- Do not commit, push, deploy, or run destructive commands unless explicitly asked.

---

## PayGrid Architecture & Operations Reference

This section exists so any Claude Code session opened in this repo — on any machine — has
real working knowledge of the system, not just the coding-style rules above. It's a living
doc: update it when you learn something a future session would otherwise have to
rediscover. Facts below were last verified 2026-10-20; re-check anything load-bearing
before trusting it blindly, especially server `.env` values and role lists (both are edited
outside git and can drift).

### Stack & repo
- Laravel 13, PHP 8.5 (confirmed on main VPS 2026-11-14; verify before trusting if this
  drifts again), Blade, MariaDB (MySQL-compatible), Redis (cache + session + queue
  in production). No frontend build step — plain Blade + inline `<script>`/CSS, Chart.js via
  CDN for charts.
- Git: `https://github.com/kelincimerah64-lab/paygrid.git`, branch `main`. **No CI/CD** —
  deploys are manual (see below), so `git log` on `main` can be ahead of what's actually
  live on a given server. Always verify live state, don't assume a merged commit is deployed.

### Infrastructure — two servers, deliberately different code
- **Main VPS** (`15.232.137.74`, user `ec2-user`, path `/var/www/paygrid`): serves the
  desktop dashboard, API, and gateway webhooks. SSH key `~/.ssh/id_ed25519_claude_paygrid`.
  Public domain `ma-paygrid.hilogate.com` (nginx config `/etc/nginx/conf.d/paygrid.conf`).
- **Mobile VPS** (`43.134.96.68`, user `ubuntu`, path `/var/www/paygrid-mobile`): serves the
  `/mobile/*` PWA (separate Android TWA app bound to the same domain). SSH key
  `~/.ssh/id_ed25519_inmarc_vps` (not re-verified this session — treat as historical until
  confirmed reachable). Main VPS's nginx reverse-proxies `/mobile`, `/mobile-manifest.json`,
  `/js/mobile-sw.js`, `/images/mobile-icon-*.png`, `/.well-known/assetlinks.json` to it.
  It runs the **same Laravel codebase** but connects to the main VPS's MySQL over a
  restricted SSH tunnel (port-forward only, no shell) rather than its own DB.
- **`routes/web.php` is supposed to diverge**: the main VPS's deployed copy should have the
  `MobileAppController` import + `/mobile/*` route group stripped (nginx already intercepts
  those paths, so the code would just be dead weight / breaks `route:cache`), while the
  local repo and the mobile VPS keep it. This divergence has silently reverted before from a
  blind full-file deploy — **before deploying `routes/web.php` to the main VPS, diff it
  against what's currently live there**, don't just copy the local file over.
- Nginx on the main VPS has a known broken `ExecReload` (systemd namespace/mount error) —
  `systemctl reload nginx` fails even though the master process stays up fine. Use
  `sudo kill -HUP <master-pid>` instead when a config change needs picking up.
- Nginx also has deliberate plain-HTTP (no redirect) exceptions for `/topup/`, `/api/topup/`,
  and `/api/callbacks/` (legacy bookmarked links + gateway webhooks) — any future
  cookie/session/HTTPS-enforcement change must be checked against these, not just the login
  page, or you'll break real merchant-facing links (this happened once already, see git
  history around `SESSION_SECURE_COOKIE`).

### Deploy process (manual, no `git pull` on servers)
1. `scp` the specific changed file(s) to the target server(s) — never the whole repo.
2. `sha256sum` the file locally and remotely, confirm they match.
3. `php artisan view:clear` (and `config:clear`/`cache:clear` if config or cached data
   changed) on the server.
4. Sanity-check with a `curl` against the live route (through nginx/HTTPS with the right
   `Host` header, not just `php -l`).
5. Only commit/push to git when explicitly asked — deploying and committing are separate,
   independently-requested actions in this project.

### Roles (`users.role`, plain string column — no enum, no migration needed to add one)
`superadmin`, `ma`, `agent`, `cs_pusat`, `approver`, `cs_monitor`, `cs_ma`, `cs_agent`,
`admin`, `readonly_admin`, `boss`, `cs`, `readonly_cs`, `finance`. Merchant-tier roles
(`admin`/`readonly_admin`/`boss`/`cs`/`readonly_cs`/`finance`) require a `merchant_id` and
are scoped by the `merchant.scope` middleware. `AuthController::homeFor()` is the single
source of truth for where each role lands post-login — deliberately **never**
`redirect()->intended()` (see Known landmines below).

### Core domain models
- **`Merchant`** — the central entity. `gateway` picks which payment-gateway client handles
  it (see Gateway integration below). `merchant_type`/`connection_type` (`cm`/`script`) is a
  long-standing enum referenced in ~24 files (sync jobs, importers, seeders) and was
  deliberately **not renamed** when fee-menus needed an Engine/Script distinction — instead
  a separate nullable `engine_type` (`sc`/`api`) column exists purely for fee-menu purposes.
- **`TopupRequest`** — collection (top-up) transactions. `channel` is a DB **generated/stored
  column** (`COALESCE(issuer_name, bank_name)` pulled from `gateway_payload` JSON) — it
  **cannot be set via mass-assignment or `$model->channel = ...`**; it only ever reflects
  real gateway payload data.
- **`MerchantWithdrawal`** — disbursement (withdrawal) transactions.
- **`Agent`** — groups merchants under one MA (`ma_user_id`).
- **`FeeMenu`** / `FeeMenuCatalog` / `config('paygrid.fee_menus')` — a rate-preset catalog
  shown when onboarding a new merchant (pick a Settlement×Engine combo, floors are hardcoded
  in config, not a DB table, since they're a fixed business formula). This is a **separate
  system** from the per-transaction fee split below — don't conflate the two "fee" systems.
- **`SupportTicket`** (System A) vs **`MerchantTicket`** (System B) — two unrelated ticket
  systems in this codebase. Check which one a request is actually about before touching
  ticket code; grepping the wrong controller/service wastes time.

### Gateway integration
`GatewayManager::for(Merchant $merchant): GatewayClientInterface` picks the client by
`$merchant->gateway`: `hilogate`/`magnetopay` → `HilogateClient` (white-labels internally via
`configKey()`, reading the `magnetopay` vs `hilogate` config block for host/timeout/secret —
Magnetopay's API has a hard **1-week max date-range** limit per request, chunk backfills
accordingly), `artageto` → `ArtagetoClient`. `AlphaClient`/`KingsPayClient` also exist in
`app/Services/Gateway/` but aren't currently wired into `GatewayManager`'s match — verify
before assuming they're live for any merchant.

### Fee / MDR model
The business uses "Fee" and "MDR" (Merchant Discount Rate) interchangeably — same thing.
- **Three independent, standalone tiers** live on `Merchant`: `merchant_mdr_percent`,
  `agent_fee_percent`, `ma_fee_percent`. These are **confirmed NOT cascading/summed** — each
  tier sets its own number, validated only against its own fixed floor in
  `config('paygrid.fee_menus')`. Do not "fix" this into a summed model; it was deliberately
  redesigned this way and confirmed correct against the source business spreadsheet.
- **Per-transaction waterfall split** (used for reports/PDFs, e.g. `MaController::
  feeAmountsByMerchant()`): `merchant_fee = volume × merchant_mdr%`; `agent_fee = volume ×
  (merchant_mdr% − agent_fee%)`; `ma_fee = volume × (agent_fee% − ma_fee%)`. Example with
  MDR Toko=1.10%, MDR Agent=0.95%, MDR MA=0.85%: Agent earns 0.15% of volume, MA earns 0.10%.
- **As of 2026-10-20**: extending the MA "Insentif" PDF (`/ma/insentif`,
  `insentifForm`/`insentifPdf` in `MaController`, view `insentif-pdf.blade.php`) from its
  current single MA-fee column into a fuller "INCENTIVE REPORT" with columns Transaction
  amount / Combined / MA / Agent / Merchant / Marketing / Disbursement, grouped under "Fee
  Distribution" and "Cash Back & Incentives". MA/Agent/Merchant/Transaction-amount/Combined
  can use the existing formula above; **Marketing has no field or formula anywhere yet** and
  **Disbursement's source is unconfirmed** (candidate: existing `disbursement_fee_fixed` on
  `Merchant`, a flat amount per withdrawal — not yet confirmed as the right field). A "PG
  fee" concept (= `volume × MDR_MA` directly, the platform's own floor-tier cut) was also
  introduced in this discussion and isn't computed anywhere in code yet. **Not yet
  implemented** — confirm the open points with the business before writing the formula.

### Caching
- `MaController::cachedAnalytics(string $key, array $filters, Closure $callback, int $ttl =
  180): array` is the central pattern: buckets `from`/`to` filters to 3-minute windows for
  stable cache keys, caches under `ma-analytics:{maId}:{key}:{md5-of-bucketed-filters}`.
  Default TTL 180s. **Bump the TTL per-key** (e.g. 300–600s) for genuinely expensive queries
  rather than chasing query optimizations that don't exist — on this table size (500k+ rows),
  a query that legitimately touches 40-70% of the rows is full-table-scan territory and
  `FORCE INDEX` does not reliably help (verified via `EXPLAIN` on production before
  concluding this — don't skip that verification step if revisiting).
- Production `.env`: `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `SESSION_CONNECTION=cache`,
  `QUEUE_CONNECTION=redis`. **The local `.env.example` defaults to `database`/`sqlite` for
  everything — don't assume production matches it.** Always check the server's actual `.env`
  before reasoning about caching/session/queue behavior.
- **Carbon mutability gotcha**: this codebase uses `Carbon\Carbon` (mutable), not
  `CarbonImmutable`, in most places. `$var->utc()` **mutates `$var` in place** — always
  `$var->copy()->utc()` unless the mutation is actually wanted. A real production bug (dates
  off by one, caused by a bare `->utc()` without `->copy()`) shipped from exactly this
  mistake — grep for bare `->utc()`/`->setTimezone()` calls on a variable that's reused
  afterward when touching date logic in this file.

### Known landmines
- `redirect()->intended()` is **deliberately never used** in `AuthController` — this app's
  live-polling JS (`data-live-root` / `public/js/paygrid-live.js`) fires background requests
  on a timer in any open browser tab, which can poison `session('url.intended')` with a
  stale URL from a *previous* role's session. Logging in always redirects to
  `homeFor($role)` instead, sidestepping this entirely. Don't reintroduce `intended()`.
- `GatewaySyncDispatcher` applies a cooldown (default 180s, up to 1800s on 401/403) per
  merchant after a sync failure, cached under `paygrid:gateway-sync:cooldown:{id}`. After
  fixing bad gateway credentials, the merchant won't actually retry until that cache key is
  manually `Cache::forget()`'d or the cooldown expires.
- UI must stay compact and genuinely scrollable on narrow/mobile screens — when adding a new
  wide table/grid to `paygrid.blade.php`, check the `@media (max-width: 800px)` block and
  add a `min-width:0` reset (plus mobile label `::before` rules if it's a `.qris-table`-style
  table) matching the existing pattern. A hardcoded desktop `min-width` with no mobile reset
  has silently clipped content (no scroll) before — this was a real customer complaint.
- `[hidden]` on an element that also has an author CSS rule setting `display:*` does **not**
  actually hide it (equal specificity, author origin wins over the UA `[hidden]` rule) —
  needs an explicit `.foo[hidden]{display:none}` override.

### Testing
- `php artisan test --filter=PayGridRoutingTest` is the main regression suite. **Baseline is
  4 pre-existing, unrelated failures** (not caused by whatever you're working on) — compare
  against that baseline, not zero, when deciding if a change introduced a regression.
- For visually verifying a page without a live browser session: render the controller's view
  server-side via `tinker` (`auth()->setUser($user)`, build a `Request`, call the controller
  method, save the HTML to a file), `scp` it down if testing against production data, serve
  it locally with `php -S 127.0.0.1:PORT` from the scratchpad dir, and open it with the
  browser tool — the rendered page is fully interactive (real JS runs).

### Secrets — never commit
- `telegram-absen-bot.n8n-workflow.json` — contains a live Telegram bot token; its own
  embedded comment says not to share it publicly. Never stage/commit this file.
- `deploy_scp.sh`, `.claude/settings.local.json` — local scratch/credential files, already
  gitignored. Keep them that way.
- `.claude/settings.json` and `.claude/skills/karpathy-guidelines.md` **are** meant to be
  committed (team-shared, no secrets).

### When this doc isn't enough
This file covers durable architecture, not day-to-day task status. For "why was X decided"
narrative history, check `git log` and commit messages (this codebase explains non-obvious
*why* in code comments, not just *what*) — and when genuinely unsure, ask the user rather
than guessing or re-deriving from scratch.
