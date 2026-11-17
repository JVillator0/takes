# Deploy (Laravel Cloud)

Production runs on [Laravel Cloud](https://cloud.laravel.com) in a dedicated organization with a **$15/month** spend alert (D-005, D-028), managed with the `cloud` CLI (v0.6.7, checked 2026-11-17). Spec: [`.claude/specs/9-deployment/SPEC.md`](../.claude/specs/9-deployment/SPEC.md).

**Production URL: <https://takes-production-aodrsk.laravel.cloud>** (first deploy 2026-11-17). Push to deploy is on for `main`: every merge redeploys the `production` environment.

## Platform check (spec 0, pre-M9 checklist)

| Requirement | Cloud offers | Repo |
|---|---|---|
| PHP 8.5 | 8.2 – 8.5; 8.5 is the default for new environments | `composer.json` `php ^8.5`, CI `PHP_VERSION: '8.5'`, Sail `sail-8.5` |
| Postgres 17 | Laravel Serverless Postgres 16, 17, 18 | CI `postgres:17`, compose `postgres:17-alpine` |
| Managed queue | Standard queue, Flex workers from 256 MiB, ≤ 3 workers on Starter, per-job delay ≤ 15 min | Laravel ≥ 13.19 and `aws/aws-sdk-php` |
| WebSockets | Laravel Reverb clusters (100 – 5,000 connections) | `laravel/reverb` arrives with spec 5 |
| Object storage | Cloudflare R2 buckets, private or public | `league/flysystem-aws-s3-v3` |
| Node | 22, 24, 26 (26 is the default) | CI uses Node 24; the environment runtime is set to Node 24 in General Settings (no CLI flag) |

No CI or compose pin had to change.

## Resources (US East, `us-east-1`)

| Resource | Name | Cloud id | Settings | Cost (pricing docs, 2026-11-17) |
|---|---|---|---|---|
| Database cluster | `takes-db` | `solitary-night-97281244` | Laravel Serverless Postgres 17, 0.25 – 1 CU, suspend after 300 s, 1-day PITR; schema `production` (`7343955`) | ≈ $0.106/h per CU awake + $0.50/GB-mo |
| WebSocket cluster | `takes-reverb` | `ws-a33fc5d3-364d-4c8a-a972-51278a695f61` | Reverb, 100 connections | $5/mo flat |
| WebSocket application | `takes` | `wsa-a33fc60f-673e-4c04-8a6c-2fa597d12f80` | on `takes-reverb`; Cloud allows the attached environment's origins | included |
| Bucket | `takes-images` | `fls-a33fc5db-2ed6-4510-a8ac-2acfc1414be0` | private, R2, key `takes-app` read/write (`flsk-a33fc5dc-4d26-4b04-b64e-467075c131b4`) | $0.02/GB-mo |
| Secret | `PIXELFORGE_API_KEY` | `scrt-a33fc5ed-eb21-49a4-bebe-163586ff2c95` | Pixelforge production key | – |
| Secret | `ANTHROPIC_API_KEY` | `scrt-a33fc5f0-7c6d-464f-be97-d54c219c2c6b` | `promptedit-takes` workspace key | – |
| Application | `takes` | `app-a33fe256-a922-4702-aee9-e2fe5eff7889` | GitHub `JVillator0/takes`, branch `main`, push to deploy | plan fee $5 (+$5 usage credit) |
| Environment | `production` | `env-a33fe259-b507-4b8b-a421-44924be4336a` | <https://takes-production-aodrsk.laravel.cloud>, PHP 8.5, Node 24; database `production`, WebSocket app `takes`, bucket `takes-images` as default disk `private` | – |
| App compute | `App` | `inst-a33fe259-c062-45cb-8401-aec407d51f80` | Flex 1 GiB (`flex-1gb`), 1 replica, scale to zero after 5 min, no scheduler (D-036) | $0.01786/h awake |
| Managed queue | `default` | `inst-a33fe2a6-877e-44d3-8539-8bed0a9b273d` | standard, Flex 256 MiB (`mq.flex.256mb`), 0 – 3 workers, visibility 180 s (extended while a job runs) | ≈ $0.13/mo polling + $1/1M ops + worker seconds |

**Monthly projection:** plan $5 + Reverb $5 + compute, database and queue ≈ $1 – 3 (all scale to zero) ≈ **$11 – 13**, less the $5 usage credit. The organization's spend alert is set at **$15**, notify only (`cloud usage --json -n` reports `alert.thresholdCents = 1500`); the CLI has no command to create further alerts.

## Build and deploy commands

Build (the environment's build command):

```sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --audit false
npm run build
if [ -d app/Filament ]; then php artisan filament:optimize; fi
```

`npm run build` reads the `VITE_REVERB_*` variables that Cloud injects when the WebSocket application is attached. `filament:optimize` runs only once `app/Filament` exists (redeploy 7, D-048).

Deploy (runs before the release goes live):

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

The roles seeder is idempotent (`findOrCreate` + `syncPermissions`, D-068). Demo data (`DemoAccountsSeeder`, showcase customer) is seeded by hand, never on deploy. There is no scheduler (D-036).

**One-off after the deploy that ships invoices (spec 6, PR #115):** run the demo seeder once by hand:

```sh
php artisan db:seed --class=DemoAccountsSeeder --force
```

It is idempotent: it creates nothing that already exists and issues the missing invoice of every paid purchase made before invoices existed (the seeded Demo purchase, and any purchase bought while #94 was live without #115), oldest payment first. Purchases made after the deploy are invoiced at payment. A backfilled purchase gets a number after those already issued that year, so number order can differ from payment order for those few rows.

## Environment variables

Trusted proxies need no setting: on Cloud (`laravel_cloud()`), the framework's `TrustProxies` middleware trusts `'*'` when no proxies are configured, so `TRUSTED_PROXIES` is not used.

Injected by Cloud: `DB_*` (attached database), `QUEUE_CONNECTION=cloud` (managed queue), `REVERB_*` and `VITE_REVERB_*` (WebSocket application), the bucket disk `private` as the default disk (`LARAVEL_CLOUD_DISK_CONFIG`), `APP_KEY`.

Set on the environment:

| Key | Value |
|---|---|
| `APP_NAME` | `Takes` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | the environment's `*.laravel.cloud` URL |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `info` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` |
| `BROADCAST_CONNECTION` | `log` until spec 5 installs `laravel/reverb`, then `reverb` (with `reverb` and no Pusher client, `routes/channels.php` fails the build's `package:discover`) |
| `PIXELFORGE_BASE_URL` | `https://pixelforge.promptedit.dev/v1` |
| `PIXELFORGE_WEBHOOK_SECRET` | the `whsec_…` of the production key, set once the dashboard webhook exists |
| `TAKES_SUPPORT_EMAIL` | support address (not set yet: the config default applies) |
| `TAKES_DAILY_CAP_PER_CUSTOMER` | `40` |
| `TAKES_DAILY_CAP_GLOBAL` | `300` |
| `WELCOME_CREDIT_USD` | `1.00` |
| `AI_SUGGESTIONS_ENABLED` | `true` |
| `AI_DAILY_BUDGET_USD` | `1` |
| `AI_DAILY_LIMIT_PER_CUSTOMER` | `30` |
| `TAKES_AI_THROTTLE_PER_MINUTE` | `6` |
| `DEMO_LOGIN_ENABLED` | `true` |
| `DEMO_LOGIN_THROTTLE_PER_IP` | `5` |
| `DEMO_ACCOUNTS_PASSWORD` | the public demo password (D-072); not set, so the config default applies |
| `ADMIN_ENVIRONMENT_LABEL` | `production` |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_AUDITOR_EMAIL`, `ADMIN_AUDITOR_PASSWORD` | not set yet (`AdminUsersSeeder` runs by hand, spec 7) |

Attached secrets: `PIXELFORGE_API_KEY`, `ANTHROPIC_API_KEY` (encrypted locally by the CLI; values are piped in, never typed or passed as flags).

Pixelforge webhook URL for the production key: `https://takes-production-aodrsk.laravel.cloud/webhooks/pixelforge` (the dashboard *ping* must return 200).

## Commands

Authenticate with `LARAVEL_CLOUD_TOKEN` exported from the password manager for the session only (it overrides any saved token and writes nothing to disk). Add `-n` to every command.

```sh
# Organization resources
cloud database-cluster:create --name=takes-db --type=neon_serverless_postgres --engine-version=17 --region=us-east-1 --json -n
cloud database-cluster:update takes-db --cu-min=0.25 --cu-max=1 --suspend-seconds=300 --json -n --force
cloud bucket:create --name=takes-images --region=us-east-1 --visibility=private --jurisdiction=default --key-name=takes-app --key-permission=read_write --json -n
cloud websocket-cluster:create --name=takes-reverb --region=us-east-1 --max-connections=100 --json -n
cloud websocket-application:create takes-reverb --name=takes --json -n
<secret reader> | cloud secret:create --name=PIXELFORGE_API_KEY --json -n
<secret reader> | cloud secret:create --name=ANTHROPIC_API_KEY --json -n

# Application (needs the GitHub source-control connection of the token's account)
cloud application:create --name=takes --repository=JVillator0/takes --source-provider=github --region=us-east-1 --json -n
cloud environment:update env-a33fe259-b507-4b8b-a421-44924be4336a --branch=main --build-command='…' --deploy-command='…' --database-id=7343955 --websocket-application-id=wsa-a33fc60f-673e-4c04-8a6c-2fa597d12f80 --json -n --force
cloud instance:update inst-a33fe259-c062-45cb-8401-aec407d51f80 --size=flex-1gb --min-replicas=1 --max-replicas=1 --scale-to-zero=true --scale-to-zero-timeout=5 --uses-scheduler=false --json -n --force
cloud managed-queue:create env-a33fe259-b507-4b8b-a421-44924be4336a --name=default --size=mq.flex.256mb --max-workers=3 --json -n   # run from the repo root: it checks composer.json
cloud instance:update inst-a33fe2a6-877e-44d3-8539-8bed0a9b273d --min-replicas=0 --max-replicas=3 --json -n --force   # Flex queue scales to zero
cloud environment-secret:attach env-a33fe259-b507-4b8b-a421-44924be4336a scrt-a33fc5ed-eb21-49a4-bebe-163586ff2c95 scrt-a33fc5f0-7c6d-464f-be97-d54c219c2c6b -n
cloud environment:variables env-a33fe259-b507-4b8b-a421-44924be4336a --action=set --key=APP_ENV --value=production -n --force   # one per key above
# Dashboard only: canvas › Bucket › takes-images › key takes-app › disk name `private`, default disk;
# Settings › General › Node version 24.

# Deploy and monitor
cloud deploy app-a33fe256-a922-4702-aee9-e2fe5eff7889 production -n
cloud deploy:monitor -n
```

The managed queue uses Cloud's defaults: visibility is extended in 3-minute steps while a job runs, Flex workers have a 90 s runtime and shutdown limit, and every delay in the code is ≤ 900 s (money.md M-17, reconcile chain 60/120/300/600/840 s), below the 15-minute cap.

## Smoke and cold start

Infra smoke after the first deploys (commits `2cf46ac` and `0575dab`, 2026-11-17): `/`, `/up`, `/register`, `/login` and `/admin/login` return 200 over HTTPS with HTTPS asset URLs; 18 migrations ran, none pending; the deploy seeder created the customer roles (`customer`, `restricted`, `showcase`) and the admin roles (`super_admin`, `operator`, `auditor`); Postgres reports 17.12; the default disk is `private` and the queue connection `cloud`. Feature smoke (takes, webhooks, live status) follows specs 4 and 5 (issue #80).

Cold start of `/` after idle (scale to zero for app and database), measured with:

```sh
curl -s -o /dev/null -w 'status=%{http_code} connect=%{time_connect}s ttfb=%{time_starttransfer}s total=%{time_total}s\n' https://takes-production-aodrsk.laravel.cloud/
```

| Measurement | Result |
|---|---|
| `/` after 12 min idle (app and database both asleep), 2026-11-17 21:01 UTC | 200, TTFB **2.98 s** (connect 0.22 s): under the 3 s target, above D-038's ≈ 0.5 s estimate, which assumed a warm database |
| `/` warm, right after | 200, TTFB 0.33 s |
| `/up` warm | 200, 0.26 s |
| Signed webhook ping after idle (target < 10 s) | pending spec 5 |

## Rotating keys

Values live in the password manager; nothing secret is stored in this repository.

- **Pixelforge or Anthropic key:** create the new key at the provider, pipe it into `cloud secret:update <secret-id> -n` (ids above), redeploy (`cloud deploy takes production -n`), verify, then revoke the old key. For Pixelforge, also update `PIXELFORGE_WEBHOOK_SECRET` with `cloud environment:variables production --action=set …` if the webhook secret changed, and re-ping the dashboard webhook.
- **Bucket key:** `cloud bucket-key:create` a new read/write key, re-attach the bucket so the environment uses it, redeploy, then `cloud bucket-key:delete` the old one.
- **Reverb credentials:** create a new WebSocket application on `takes-reverb`, attach it with `environment:update --websocket-application-id=…`, redeploy (the build re-embeds `VITE_REVERB_*`), then delete the old application.
- **Cloud API token:** create a new token in the Cloud dashboard (API tokens), store it in the password manager, then revoke the old token.
- **`APP_KEY`:** rotate with `APP_PREVIOUS_KEYS` set to the old key so existing sessions and encrypted values still decrypt; redeploy.
