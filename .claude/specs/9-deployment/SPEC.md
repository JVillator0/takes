# 9 · Deployment (Laravel Cloud)

Status: **Approved** · Milestone: **M9 Deploy** (required) · Depends on: 3, 4, 5 (required scope, incl. welcome credit + minimal Buy credits from spec 4), then 6–8 redeploys · Worktree: main
Reviews: product ☑ · technical ☑ · Decisions: D-005, D-019, D-026, D-028, D-030, D-036, D-038, D-045, D-046, D-047, D-050, D-059, D-063

## Target
Laravel Cloud, in a **separate organization** with a **$15/month spending limit** (D-005 as raised by D-028), managed through the `cloud` CLI (version and prices re-checked at deploy time; last verified 2026-11-17). The Cloud token comes from the password manager and is read at runtime, never written to disk. (Vault and item names are kept in a private note, not in this public repo.)

## Resources (US East)
| Resource | Choice | Cost (pricing docs, 2026-11-17) |
|---|---|---|
| App compute | Flex 1 GiB, 1 replica, scale to zero (D-038) | $0.01786/h awake |
| Database | Serverless Postgres, 0.25–1 CU, scale to zero (D-038) | ≈ $0.106/h per CU awake + $0.50/GB-mo |
| Queue | Managed queue, standard, Flex 256 MiB, 0–3 workers | ≈ $0.13/mo polling + $1/1M ops + worker seconds |
| WebSockets | Reverb cluster, 100 connections | **$5/mo flat** |
| Object storage | 1 bucket (take images) | $0.02/GB-mo |

### Budget (D-028, D-038)
Plan fee $5 (+$5 usage credit) and Reverb $5/mo flat; compute, DB and queue add ≈ $1–3. The $15 limit covers this with margin, so Reverb runs in production and live status is the headline. Scale-to-zero stays on both app and DB: always-on compute does not fit the limit. Cold start ≈ 0.5 s is accepted and **measured** (see smoke test). If spend approaches the limit, remove nothing silently: the client already falls back to polling when the socket is not connected (no env switch), so a Reverb outage degrades gracefully. A day-2 spend check and a Cloud spend alert are part of acceptance; never rely on the limit pausing compute during the review window.

## Configuration
- Build: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (with the `VITE_REVERB_*` variables set at build time), and `php artisan filament:optimize` **only once `app/Filament` exists** (from redeploy 7, D-048; the first deploy of specs 3-5 runs before Filament is installed and must not call it). Deploy: `php artisan migrate --force`. Only the roles/permissions seeder runs in the deploy script; demo data is seeded by hand (below).
- Env: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `LOG_CHANNEL=stderr`, `TRUSTED_PROXIES`, secure cookies, `PIXELFORGE_*` (production key), `ANTHROPIC_API_KEY` (workspace key), `BROADCAST_CONNECTION=reverb`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=cloud` (injected), `FILESYSTEM_DISK` set to the Cloud object-storage bucket disk (take images are private, spec 4), `DEMO_LOGIN_ENABLED=true`, `DEMO_LOGIN_THROTTLE_PER_IP=5`, `WELCOME_CREDIT_USD=1.00`, `AI_SUGGESTIONS_ENABLED=true`, `AI_DAILY_BUDGET_USD=1`, `TAKES_DAILY_CAP_PER_CUSTOMER=40`, `TAKES_DAILY_CAP_GLOBAL=300`, `AI_DAILY_LIMIT_PER_CUSTOMER=30`, `TAKES_AI_THROTTLE_PER_MINUTE=6`, `TAKES_SUPPORT_EMAIL` (names and formats owned by 3-01; USD values are decimal strings).
- Public registration works without email verification (D-030).
- **No scheduler** (D-036): reconcile is job-driven and an opportunistic sweeper (throttled, ≥ 2 min) is triggered by page loads; widgets don't poll, so the app and DB can sleep.
- Queue rules: Cloud's queue is at-least-once with a 15 min max delay, so reconcile delays ≤ 14 min and job timeouts stay below the visibility timeout; all jobs idempotent.
- Managed queues need Laravel ≥ 13.19 and `aws/aws-sdk-php`.
- Abuse and quota protection (Pixelforge limits are per account: 60 rpm, 3 concurrent, 500/day): the per-IP throttle on the ephemeral demo endpoint (`POST /demo`) is built by spec 4; this spec only sets its env value (`DEMO_LOGIN_THROTTLE_PER_IP`) and verifies it in the smoke test. There is no separate registration throttle (registration is open by D-030; the global take cap bounds spend). The caps are owned by 3-01 (`TakePolicy`, `DenialReason`, `config/takes.php`, D-050): this spec only sets the env values (per-customer 40/day, global 300/day); the global cap denies with `demo_quota_reached` (shared daily capacity used up, any customer), the per-customer cap with `daily_take_cap`. Use a separate Pixelforge key per environment and avoid heavy local runs against the same account while evaluators test.

## External keys (Javier pastes secret values into the password manager; agents never type keys into web forms)
1. Pixelforge **production** key, webhook URL `https://<app>.laravel.cloud/webhooks/pixelforge`; dashboard *ping* must return 200.
2. Anthropic workspace **promptedit-takes**, its API key, workspace monthly spend limit **$5** (D-046).
3. Values set in Cloud env via `cloud` CLI from piped secret reads.

## Seeding and data (what may exist in production)
- Production seeding creates **users, demo customers and credit only**, never fake takes or usage records (the full-history dataset, D-037, is for local/CI/screenshots only; the seeded Demo customer gets a $10 purchase in the ledger and no takes): every take comes from a real Pixelforge call, so the admin reconciliation (A04) can match Pixelforge's records. Wireframe volumes are illustrative.
- **Roles and permissions are seeded by the deploy script**: `RolesAndPermissionsSeeder` (customer roles `customer`/`restricted` and their permissions from 3-01; admin roles join at redeploy 7) runs on the first deploy and idempotently on every redeploy (`firstOrCreate`), because registration assigns the `customer` role and must work from the first request.
- The **demo/showcase seeder** is a one-off, idempotent command (create a customer only if missing; grant `promo_grant` only on creation, guarded by the unique rule from 3-03), run by hand, not on every deploy.
- Smoke data is created by a dedicated **showcase** customer (read-mostly, credentials in the README, D-045), so the documented demo balances are untouched. "Try a demo account" creates ephemeral customers with the welcome credit, throttled per IP.

## Smoke test (leaves real generations for the evaluators)
**First deploy (specs 3-5):** prompts: normal, `[succeed]`, `[fail]`, `[duplicate] [succeed]`, `[slow]`, `[blocked]`, `[overloaded]`, plus one remix, all as the showcase customer. Also verify as a fresh user: registration with the welcome credit, minimal Buy credits modal, a failed take (charge 0), and the mobile layout (P10). **After redeploy (6-8):** one AI suggestion, a purchase with invoice, admin pages.
Measure and record in the README: cold-start time of `/` and of the signed webhook ping after idle (target: first page load < 3 s, ping < 10 s because Pixelforge's deadline is 10 s).
Then: Pixelforge dashboard deliveries show 2xx; `php artisan billing:verify --usage` (owned by 3-03, D-059) passes. After redeploy (7): admin Pixelforge usage page shows a match for each day with data (today shows "N in flight", D-034); Reports margin = 20.0%.
Final check: `billing:verify` (no usage open, no take non-terminal, balance = Σ ledger).

## Acceptance criteria (split, D-063)
**First deploy (specs 3, 4, 5; required scope)**
- [ ] Public URL serves landing, registration, "Try a demo account", and the workspace with live status (Reverb connected; polling fallback verified by blocking the socket).
- [ ] Registration + welcome credit, minimal Buy credits modal, failed take and mobile layout work on the public URL.
- [ ] Dashboard ping returns 200 with a valid signature; an invalid-signature `curl` returns 401 (D-056).
- [ ] A `[duplicate] [succeed]` take produces exactly one ledger charge; a `[slow]` take survives an idle period.
- [ ] Generations left on the deployed app match Pixelforge's records (`billing:verify --usage`, `/v1/generations` diff).
- [ ] No usage open and no take non-terminal at the end.
- [ ] Cold-start numbers recorded; projected monthly spend < $15 from the Cloud dashboard after 24 h; spend after 48 h within the limit; alert configured.

**After redeploy (specs 6, 7, 8; only for what was finished)**
- [ ] Billing page and invoices (6), `/admin` with the auditor, Pixelforge usage page match and Reports margin 20.0% (7), one AI suggestion (8) work on the public URL.
- [ ] `billing:verify --usage` still passes after the redeploy.

**Release**
- [ ] After tagging `v1.0.0`, auto-deploy is disabled (spec 10, D-031, D-043).

## Issues
1. Cloud app + environment + resources via CLI (after approval of each resource); spend alert.
2. Env vars from the password manager; Pixelforge production key + webhook; Anthropic workspace + key.
3. Deploy, migrate, seed roles/permissions (idempotent); idempotent seed command by hand (demo accounts, showcase customer); set the throttle and cap env values (the demo throttle is built in spec 4, caps and `config/takes.php` in 3-01).
4. Smoke test, cold-start measurements, final SQL check; record results in the README.

## Open questions
- None (Anthropic limit decided: $5, D-046).
