# Takes

Image-generation studio on top of Pixelforge (a simulated, asynchronous image API), built as the PromptEdit take-home. Every generation is a **take**, takes live in **projects**, any take can be **remixed**, and **Describe a change** turns a sentence into a reviewed parameter diff with a new price. Customers pay provider cost **+ 25%** from prepaid (simulated) credits; every provider call (Pixelforge and Anthropic) goes through one multi-provider ledger.

Stack: Laravel 13 · PHP 8.5 · Inertia v3 · Vue 3 + TypeScript (strict) · Tailwind 4 · Postgres 17 · Pest 5 · Larastan · vite-plus (`vp`) · Filament 5 (arrives in M7, D-048).

Everything committed to this repository (code, comments, commits, PRs, reviews, docs) is written in **English**, whatever language the chat uses.

## Where things are

| Path | What |
|---|---|
| `.claude/specs/` | Approved product and engineering specs. Start with `README.md`, then the child spec for your task. `DECISIONS.md` holds every `D-NNN` decision quoted below. |
| `.claude/rules/architecture.md` | Actions, Queries, DTOs, Enums, Exceptions, controllers, jobs. |
| `.claude/rules/money.md` | Money checklist (nanodollars, ledger, holds, locks, display). Each rule names its test. |
| `.claude/rules/webhooks.md` | Webhook checklist (signature, idempotency, ordering, reconcile, broadcast). Each rule names its test. |
| `.claude/rules/frontend.md` | Inertia v3 + Vue + TS, money as server strings, copy from `lang/en/*.php`. |
| `.claude/rules/filament.md` | Admin console layout and permissions (M7). |
| `.claude/agents/` | Reviewer agents (`laravel-backend-reviewer`, `inertia-vue-reviewer`, `filament-reviewer`) and the PR judge. |
| `.claude/skills/` | Boost skills, `agent-worktrees`, `modern-web-guidance`, `pr-review`. |
| `.claude/worktree-pool.md` | The five-worktree pool (`takes-a` … `takes-e`, D-070): URLs, databases, ports. |
| `.ai/guidelines/` | Short project guidelines that Laravel Boost merges into its generated guidance. |

Read the rules file that covers the paths you are about to touch **before** writing code. The reviewers check against those files, not against memory.

## Commands

| Command | Runs |
|---|---|
| `composer check` | Pint (test mode) → Larastan → filacheck (once `app/Filament` exists) → Pest → `composer audit` |
| `composer stan` | Larastan only |
| `npm run check` | `vp check`: format, lint and type-aware lint for `resources/js` |
| `npm run types` | `vue-tsc --noEmit` (strict) |
| `vendor/bin/pint <file>` / `npx vp fmt <file>` | Format one PHP / Vue or TS file (the PostToolUse hook does this for you) |

Tests:
- Main checkout: `vendor/bin/pest` (database `takes_testing`).
- Pool worktree `takes-<letter>`: always pass the letter's database, e.g. `DB_DATABASE=takes_testing_b vendor/bin/pest tests/Feature/Webhooks`. Two processes on one test database drop each other's tables.
- Run the narrowest set that covers the change; only one full suite at a time across the pool.
- Concurrency tests are in the Pest group `concurrency` and run serially: `vendor/bin/pest --group=concurrency`.

## Invariants (never break these; details and tests in `.claude/rules/`)

**Money** (`.claude/rules/money.md`)
- Amounts are integer **nanodollars** (`int` / `bigint`, `App\Support\Money\Nanodollars`). No floats anywhere in money code or rate config.
- Lock order, the only one: **take | suggestion → customer**. The usage record is re-read under the customer lock, never locked separately.
- Take status changes only in `ApplyTakeSnapshotAction` and `FailTakeAction`; settlement (settle or void) happens exactly once, inside the terminal transition.
- No HTTP call inside a database transaction. No queue delay or backoff above 900 s.
- Holds are state, not ledger rows (D-024): `available = balance − held`. Ledger types are `purchase`, `promo_grant`, `charge`, `adjustment` only.
- The server formats money; the frontend never does money math. Exact amounts keep ≥ 2 decimals; rounded display (lists, cards, toasts, pill) is always 4 decimals, never 2 (D-042).
- Customers never see provider cost, tokens, provider names or margin (D-023).

**Webhooks** (`.claude/rules/webhooks.md`)
- Signature is verified on the raw body; invalid, missing or stale ⇒ `401` and nothing is written (D-056).
- `event_id` is recorded once in `webhook_events`; every verified delivery appends one `webhook_deliveries` row with its outcome (D-027).

**Auth** (D-012, D-032)
- Admins: Laravel's `users` table and the default `web` guard (Filament panel in M7).
- Customers: `customers` table and the `customer` guard. Customer routes use `auth:customer` **plus** the middleware that calls `Auth::shouldUse('customer')`, so `$request->user()`, policies and Inertia shared props resolve the customer. `web` stays the default guard.
- Broadcast channel `App.Models.Customer.{id}` is authorised with `['guards' => ['customer']]`.
- Permissions say what a role may do; credits are state checked by policies (`Response::deny` with a `DenialReason`) and re-checked under lock in the action (D-015). Never toggle permissions on balance.

## Working rules

- Conventional Commits for commits and PR titles (D-039). Never push to `main`, never force-push; PRs are squash-merged by the review workflow (spec 1-03).
- Every PR follows the template (Summary · Why · Spec section · Changes · Money impact · Test evidence · Screenshots · Risks · Checklist) and references its spec section.
- UI copy has one source: the tables in spec 2-01, loaded from `lang/en/*.php` (D-057). Never restate a string in code or in another spec.
- Pool worktrees never call the real Pixelforge API: provider limits are per account (60 rpm, 3 in flight, 500/day). Use `Http::fake` and signed fixtures; see `.claude/skills/agent-worktrees/SKILL.md`.
- Never commit secrets, `.env`, or licensed files (Filament Blueprint is local-only, D-003).

## Laravel Boost guidance

Boost generates `AGENTS.md` (and its `<laravel-boost-guidelines>` block); `php artisan boost:update` rewrites only that block, never the content above. Project guidelines for Boost live in `.ai/guidelines/`.

@AGENTS.md
