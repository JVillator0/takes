# Takes

**Takes** is an image-generation studio built on the Pixelforge API, for the PromptEdit take-home ([assessment](https://pixelforge.promptedit.dev/assessment)). It borrows the vocabulary of video editing: every generation is a *take*, takes live in *projects*, any take can be *remixed*, and *Describe a change* turns a sentence into a reviewed parameter diff with a new price. Customers pay the provider's cost plus 25% from prepaid (simulated) credits, and every provider call goes through one ledger so the money is exact and explainable.

> **Status:** work in progress. The app is currently the Laravel 13 Vue starter kit on Postgres 17; the product is built milestone by milestone from the specs in [`.claude/specs/`](.claude/specs/README.md).

## Brief coverage

*To be completed at submission:* a matrix of every requirement, tip and bonus of the brief, with how to see it live, the code path and the Pest test that proves it.

## Run locally

> **Prerequisites**
> 1. Your own Pixelforge API key (`PIXELFORGE_API_KEY`) to generate images.
> 2. A webhook tunnel is optional: the reconcile polling job finishes takes without webhooks.
> 3. Set `AI_SUGGESTIONS_ENABLED=false` to run without an Anthropic key.
>
> These variables are added to `.env.example` with the Pixelforge client (spec 3-04) and the AI feature (spec 8).

Requirements: PHP 8.5, Composer 2, Node 24, PostgreSQL 17 (or Docker for the Sail path).

### Option A: Laravel Valet (macOS)

```bash
git clone https://github.com/JVillator0/takes.git && cd takes
composer install && npm ci
cp .env.example .env
php artisan key:generate
# Point DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD in .env at your Postgres 17, then:
createdb takes && createdb takes_testing
php artisan migrate --seed
valet link takes && valet secure takes
npm run dev
```

Open https://takes.test.

### Option B: Laravel Sail (Docker)

```bash
git clone https://github.com/JVillator0/takes.git && cd takes
composer install          # needs local PHP; otherwise see the Sail docs for a one-off Composer container
cp .env.example .env
```

Override these values in `.env` for Sail:

```dotenv
APP_URL=http://localhost
DB_HOST=pgsql
DB_PORT=5432
DB_USERNAME=sail
DB_PASSWORD=password
# Live status (optional; without it pages poll). PHP containers reach Reverb by
# service name, browsers through the published port on localhost.
BROADCAST_CONNECTION=reverb
REVERB_HOST=reverb
VITE_REVERB_HOST=localhost
```

Set `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` to any values of your choice.

```bash
./vendor/bin/sail up -d                 # app, Postgres 17, a queue worker and Reverb (compose.yaml)
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm ci && ./vendor/bin/sail npm run dev
```

Open http://localhost. To run the tests under Sail, create the test database once: `./vendor/bin/sail exec pgsql createdb -U sail takes_testing`, then `./vendor/bin/sail artisan test`.

### When webhooks never arrive

Webhooks are an optimisation, not a dependency (spec 5). To see it, point the key's webhook URL nowhere (or delete a delivery in the Pixelforge dashboard) and generate a take:

1. When Pixelforge accepts the take, a reconcile chain starts: `GET /v1/generations/{id}` 60 s later, then after 120, 300, 600 and 840 s (each delay ≤ 900 s for the managed queue), until the take is succeeded or failed. It settles with the generation's own rates, exactly as a webhook would; after about 32 min without a result the take is flagged `reconcile_exhausted` for Needs attention.
2. Expected latency: a normal take (5 to 30 s) is settled by the first poll, **60 s after it was accepted**. A `[slow]` take (60 to 90 s) is picked up by the second poll, about 180 s after acceptance, so up to roughly 2 minutes after it finished.
3. If a chain is lost (a worker died), any signed-in page load (workspace, billing, the admin console) runs the opportunistic sweeper, at most once every 2 minutes, which queues a poll for every non-terminal take older than 2 minutes. No scheduler is needed (D-036). By hand: `php artisan takes:reconcile --take=<id>` or `--stale`.
4. The open page polls `takes`/`counts` every 4 s while the socket is down, so the card updates as soon as the take settles.

Pool worktrees never receive webhooks (D-047), so every take there finishes this way.

## Quality

| Command | What it runs |
|---|---|
| `composer test` | Pint (check), Larastan, then the Pest suite |
| `composer lint` | Pint (fix) |
| `npm run check` | Frontend format, lint and type-aware lint (Vite+) |
| `npm run types:check` | `vue-tsc --noEmit` |
| `composer ci:check` | `npm run check`, `npm run types:check` and `composer test`, as CI runs them |

The suite runs offline with no provider keys. CI (GitHub Actions, `postgres:17`) runs `composer ci:check` on every pull request.

Tests use the `takes_testing` database (`phpunit.xml`). To point them elsewhere, export it: `DB_DATABASE=<name> composer ci:check`. In the parallel worktrees used to build this project, `scripts/test.sh` does that per checkout (see [`.claude/worktree-pool.md`](.claude/worktree-pool.md)).

## Deploy

Live at **<https://takes-production-aodrsk.laravel.cloud>** on Laravel Cloud (US East): app on Flex compute, Serverless Postgres 17, a managed queue, a Reverb cluster and a private object-storage bucket, all scaling to zero except Reverb, under a $15/month spend alert. Every merge to `main` deploys.

| Cold start (2026-11-17) | Result |
|---|---|
| `/` after 12 min idle (app and database asleep) | 2.98 s to first byte |
| `/` warm | 0.33 s |
| Signed webhook ping after idle | measured with spec 5 |

Resources, build and deploy commands, environment variables and key rotation are in [`docs/deploy.md`](docs/deploy.md).

## Design notes

- **Invoice numbers are gapless per year** (`INV-2026-000004`, spec 6). Each purchase increments its year's row in `invoice_counters` under a row lock that is held until the purchase commits (lock order: customer, then the counter), so a rolled-back purchase leaves no gap. The cost is that purchases from all customers serialise on that one row for the length of their transaction, which is a few milliseconds and acceptable at this scale; a sharded or post-commit numbering would be the next step if purchases ever became hot.
- **The billing page's Activity rebuilds the customer's history on every view** (spec 6). The running balance is a window sum over one ordered stream of ledger entries and usages, so each page sorts the whole history once and then takes 25 lines with LIMIT/OFFSET. That is one indexed scan of `ledger_entries` and `usage_records` per customer, cheap at demo volumes (dozens to thousands of rows). If a customer's history ever grew large, the fix is keyset pagination plus a stored `balance_after` on each ledger entry.

## Harness files

*To be completed:* the files that steer the agents that built this project.

| Path | Purpose |
|---|---|
| [`.claude/specs/`](.claude/specs/README.md) | Approved product and engineering specs, reviews and the decision log |
| [`.claude/worktree-pool.md`](.claude/worktree-pool.md) | Parallel work: worktree pool, lease protocol, shared provider limits |
| [`scripts/`](scripts/) | Worktree setup (`worktree-env.sh`), per-worktree tests (`test.sh`), secrets into `.env` (`env-from-1password.sh`) |

## How this was built

*To be completed at submission:* specs → GitHub Project → pull requests reviewed by specialist agents and a judge, with links to the Project, representative PRs and the wireframes, and the time spent on the required part versus stretch.
