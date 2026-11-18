# 0 · Repository and stack

Status: **Approved** · Milestone: **M0 Foundations** · Depends on: — · Unblocks: 1 · Parallel: no (lead only)
Reviews: product ☑ · technical ☑

## Goal
A public repository with a pristine, reproducible Laravel app on the agreed stack, runnable by an evaluator in minutes, with no secrets and no licensed dependencies.

## Decisions
- D-001 stack, D-003 Blueprint local-only, D-004 secrets in 1Password, D-010 name, D-012 identities, D-016 repo timing, D-039 conventional commits, D-041 specs location, D-043 versioning, D-047 local webhooks, D-060 gate A.

## Repository
- GitHub `JVillator0/takes`, **public**, default branch `main`, created only after specs are approved (D-016).
- First commit: `.claude/specs/` including every `REVIEW.md`; `_draft-v0.md` is not committed (planning history stays in `DECISIONS.md` and the reviews). Second commit: pristine `laravel new` scaffold. From then on, every change goes through a PR (spec 1-03). Commit messages and PR titles follow **Conventional Commits** (D-039); squash merge keeps the PR title, and a PR-title check in CI and in the PR template/judge gates enforces it. Releases: `v0.N.0` tag + GitHub Release per closed milestone, `v1.0.0` only at submission (D-043).
- Local layout:
  ```
  ~/Documents/Work/promptedit/
  ├── takes/        main checkout (lead session), branch main
  ├── takes-a/      pool worktree A
  ├── takes-b/      pool worktree B
  └── takes-c/      pool worktree C
  ```
- Repo settings: squash merge only, auto-delete head branches, branch protection on `main` enabled **after the first green CI run on `main`**, requiring the exact job names defined in spec 1-01 for the **minimal Gate A set only** (Pint, Larastan 8, Pest + arch, `vue-tsc`, PR-title check; linear history, no force-push); gitleaks, filacheck, audits and the coverage gate `--min=85` (on at Gate B) are added to the required list when they join (D-060), Issues + Projects enabled, Wiki disabled.
- Files at root: `README.md`, `LICENSE` (MIT), `.editorconfig`, `.gitattributes`, `.nvmrc`, `.env.example`, `CLAUDE.md`, `.claude/specs/`.

## Stack (verified on Packagist 2026-11-17)
| Concern | Choice | Notes |
|---|---|---|
| PHP | 8.5 | `bcmath` not required (pure integer math). |
| Framework | Laravel **13.x** | Vue starter kit: Inertia v3, Vue 3, **TypeScript**, Tailwind 4, shadcn-vue/reka-ui, Wayfinder, Fortify. |
| DB | PostgreSQL **17** | CHECK constraints and partial indexes are used deliberately. |
| Realtime | Laravel Reverb 1.13 + `@laravel/echo-vue` | Production uses Reverb (D-028); the client falls back to polling automatically when the socket is not connected, no env switch (spec 5). |
| Admin | Filament **5.x** + Shield 4 + `elemind/filament-echarts` + `rmsramos/activitylog` + `pxlrbt/filament-environment-indicator` | Same plugin set as Buxy/Tezora. Filament, Shield, MFA columns and admin role seeding are installed when M7 starts (D-048); M0 and M3 stay on starter kit + Postgres + Reverb + Pest, and M3 builds only the customer guard, customer-guard roles/permissions and policies. |
| Permissions | `spatie/laravel-permission` (via Shield) | Two guards: `web` default for admins, `customer` for customers with `Auth::shouldUse` (D-012, D-032; spec 3-01). |
| AI | `laravel/ai` 1.x, Anthropic provider, `claude-haiku-5-5` | Spec 8. |
| Tests | Pest **5** + pest-plugin-laravel | Arch tests. |
| Static analysis | Larastan 3, **level 8** | |
| Style | Pint (`laravel` preset), ESLint + Prettier (starter kit) | |
| Filament lint | `laraveldaily/filacheck` | |
| Queue | `database` locally; Laravel Cloud managed queue in production | Requires `aws/aws-sdk-php`. Cloud's queue is at-least-once with a 15 min max delay: **no job `delay`/`release` above 900 s** (rule in `.claude/rules` + a Pest test with `Queue::fake()` on every job's max backoff and on `SubmitTakeJob` release delays, including the persist-image job, whose backoff is `[30, 300, 900]` with `retryUntil` = completed_at + 23 h), every job idempotent. |
| Sessions / cache | `database` | |
| Storage | A **private** local disk locally (`local` driver with `serve` and temporary URLs, never `public`); Laravel Cloud Object Storage in production | Persisted take images (D-020), owner-only access; the default disk (`FILESYSTEM_DISK`, spec 4). |

Packages **not** used: spatie/laravel-data (house style uses plain DTOs), Horizon (managed queue in prod), Telescope, any money library (integers are enough).

## Environments
| Env | Where | Purpose |
|---|---|---|
| local | Valet (`takes.test`, `takes-a.test`…) | Daily work, parallel worktrees (spec 1-04). |
| local-sail | Sail (`compose.yaml`) | Evaluator-friendly run; must keep working. |
| ci | GitHub Actions + `postgres:17` service | Quality gates. |
| production | Laravel Cloud (spec 9) | Public demo. |

Local webhooks use plain ngrok with a random URL (D-047): if the tunnel restarts, update the local key's webhook URL in the Pixelforge dashboard; after the one-session build the Laravel Cloud production environment is used.

Pixelforge: one API key per environment (local and production), each with its own webhook URL and secret (brief requirement). Pixelforge limits (3 concurrent, 500/day, 60 rpm) are **per account**, so local dev and the deployed app share them: avoid parallel local runs while evaluators test.

**Pre-M9 checklist:** confirm in the Cloud dashboard that PHP 8.5, Postgres 17, the managed queue (`aws/aws-sdk-php`) and Object Storage (S3 driver) are available; pin PHP to what Cloud offers so CI matches production.

## Configuration and secrets
- `.env.example` lists every variable with safe defaults and comments; nothing secret.
- Variables introduced by this project: `PIXELFORGE_BASE_URL`, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, `PIXELFORGE_WEBHOOK_TOLERANCE=300`, `ANTHROPIC_API_KEY`, `AI_SUGGESTIONS_ENABLED`, `AI_DAILY_BUDGET_USD`, `DEMO_LOGIN_ENABLED`, `DEMO_LOGIN_THROTTLE_PER_IP=5`, `WELCOME_CREDIT_USD` (D-019), `TAKES_DAILY_CAP_PER_CUSTOMER=40`, `TAKES_DAILY_CAP_GLOBAL=300`, `AI_DAILY_LIMIT_PER_CUSTOMER=30` (D-050), `TAKES_AI_THROTTLE_PER_MINUTE=6`, `TAKES_SUPPORT_EMAIL` (shown in the restricted-account copy). All of these feed the single `config/takes.php` (keys `takes.daily_cap_per_customer`, `takes.daily_cap_global`, `takes.support_email`, `takes.welcome_credit_nanos`, `takes.demo.enabled`, `takes.demo.throttle_per_ip_per_hour`, `takes.ai.enabled`, `takes.ai.daily_budget_nanos`, `takes.ai.daily_limit_per_customer`, `takes.ai.throttle_per_minute`; the table is in spec 3-01), created there; specs 4 and 8 only read it. The two USD env values (`AI_DAILY_BUDGET_USD`, `WELCOME_CREDIT_USD`) are **strings** in `.env`, parsed once in `config/takes.php` through `Nanodollars::fromDecimalString` (never `(float)`/`env(..., 1.0)`), so config holds integer nanodollars; the arch float scan also covers `config/pixelforge.php` and `config/ai-pricing.php`.
- `config/ai-pricing.php` records the source and date of the Haiku 5.5 rates next to them; a test checks them against the model table; AI charges are computed from the API's `usage` block, never from an estimate (spec 8).
- `scripts/env-from-1password.sh <env>` writes `.env` from 1Password using `op read`; output is git-ignored. Item names are listed in the script, values never.
- Secret values are created by the services (Pixelforge dashboard, Anthropic console, Laravel Cloud) and pasted into 1Password **by Javier**; agents only read them through `op`.

## Acceptance criteria
- [ ] `git clone` + `composer install` + `npm ci` work with **no** private credentials.
- [ ] `./vendor/bin/sail up` brings up app, Postgres, Reverb and a queue worker; README documents it.
- [ ] No secret in git history (`gitleaks` job in CI, spec 1-01).
- [ ] `.env.example` covers every `env()` call in `config/` (a Pest test greps `config/` for `env()` keys and compares).
- [ ] `composer check` and the full Pest suite pass offline with **no secrets** (HTTP fakes), also in CI.
- [ ] Without `PIXELFORGE_API_KEY` the app boots and shows the 2-01 *service not configured* banner (no 500; `isConfigured()` and the `imageService.configured` prop are 3-04 issue 4, the banner UI and composer disabling are spec 4 issue 8, with a feature test that boots without the key); README "Run locally" has a 3-line prerequisites box: own Pixelforge key; tunnel optional because reconcile polling works; `AI_SUGGESTIONS_ENABLED=false` to run without Anthropic.

## Issues
1. Create repo `JVillator0/takes` with settings (squash only, auto-delete branches, Issues + Projects). Branch protection is 1-01 issue 5, after the first green CI run.
2. Commit `.claude/specs/`; commit pristine scaffold. (PR template and PR-title check: 1-01 issue 5.)
3. Switch DB to Postgres 17; `.env.example`; `compose.yaml` with pgsql, reverb, queue.
4. `scripts/env-from-1password.sh` and README "Run locally" (Valet and Sail, prerequisites box).
(The `.env.example` coverage, 900 s max delay and config float-scan tests are written in 1-01 issue 2, once Pest exists.)

## Open questions
- None blocking.
