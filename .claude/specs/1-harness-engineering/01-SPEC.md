# 1-01 · Quality gates and CI

Status: **Approved** · Milestone: **M1 Harness** · Depends on: 0 · Unblocks: Gate A (parallel work) · Parallel: no
Reviews: product ☑ · technical ☑

## Goal
Make "correct and consistent" mechanical: every PR is blocked unless formatting, static analysis (PHP **and** TypeScript), architecture rules, tests and security checks pass. These gates exist **before** any feature code, so parallel agents can't drift.

## Local commands (composer scripts, house style from ahorrosv/Buxy)
| Script | Runs |
|---|---|
| `composer lint` | `pint --test --parallel` |
| `composer stan` | `phpstan analyse` (Larastan, level 8, paths `app database routes`) |
| `composer filacheck` | `vendor/bin/filacheck` |
| `composer test` | `pest --parallel` |
| `composer check` | lint → stan → filacheck (skips if `app/Filament` is absent; Filament arrives with M7, D-048) → test → `composer audit --locked` |
| `npm run lint` / `format:check` | ESLint / Prettier (starter kit) |
| `npm run types` | `vue-tsc --noEmit` with `strict: true` |

## PHP rules
- `declare(strict_types=1);` everywhere (Pint rule).
- Larastan **level 8**, no baseline. `treatPhpDocTypesAsCertain: false`. Array shapes documented on DTO `fromArray()`.
- **Pest arch tests** (`tests/Arch/ArchitectureTest.php`):
  - Actions: `final readonly`, only public method `execute()`; Queries: `final`, `execute()`.
  - DTOs `final readonly`; Enums backed.
  - Controllers don't use `DB`, query builders or `App\Services\*`.
  - `env()` only in `config/`.
  - No `float` type, `floatval`, `round(`, `number_format(` in `App\Support\Money`, `App\Actions\Billing`, `App\Actions\Takes`, `App\Actions\Suggestions` (source scan). Display rounding (D-021) exists **only** in `Nanodollars::format()`, implemented with integer arithmetic (`intdiv` + remainder; half-up = add half, then `intdiv`; min down, max up), so the scan passes without exceptions. A unit test covers the boundaries (x.49999, x.5, exact).
  - Models outside `App\Models` forbidden; `App\Services\Pixelforge` used only from Actions, Queries, Jobs, the Inertia shared-props middleware and Filament pages/widgets (never from controllers, models or Vue-facing resources).
  - No `dd`, `dump`, `ray`.

## TypeScript / Vue rules
- `tsconfig` `strict: true`, `noUncheckedIndexedAccess: true`.
- No `any` (ESLint `@typescript-eslint/no-explicit-any: error`).
- Shared types in `resources/js/types/` mirror API resources; Wayfinder for routes.
- Money arrives as **strings** formatted by the server (never JS numbers for amounts).

## CI: `.github/workflows/ci.yml`
Triggers: `pull_request`, `push` to `main`. `concurrency` cancels superseded runs. Jobs. **Minimal Gate A (required from the empty scaffold, D-060):** `lint` (Pint, ESLint, Prettier, compose config), `static` (Larastan L8, `vue-tsc`), `test` (Pest + arch) and `pr-title`. **Added when first relevant:** filacheck (when `app/Filament` exists, M7), `security` gitleaks (once secrets/config exist; required from then on), `composer audit`/`npm audit` (not required), coverage `--min=85` (Gate B). Branch protection lists only the minimal required set at first and grows with the later jobs.

| Job | Steps |
|---|---|
| `lint` | Pint `--test`, ESLint, Prettier check, `docker compose config` (compose file stays valid; no separate Sail job) |
| `static` | Larastan L8, filacheck (skipped until `app/Filament` exists), `vue-tsc --noEmit` |
| `test` | `postgres:17` service with a **superuser** role (paratest creates one DB per process); PHP 8.5 (pdo_pgsql, pcov); `composer install`; `npm ci && npm run build`; `pest --parallel` (group `concurrency` excluded), then `pest --group=concurrency` serially (uses `DatabaseMigrations`, real transactions, spec 3-03). Coverage `--min=85` is enabled only after M3 (Gate B) |
| `pr-title` | PR title must be a Conventional Commit (`feat|fix|chore|docs|test|refactor|ci(scope)?: …`, D-039); squash merge keeps the title as the commit message. Runs on `pull_request` (opened, edited, synchronize) |
| `security` | **gitleaks** (required once added). `composer audit --locked` and `npm audit --audit-level=high` run on dependency-file changes and on a weekly schedule, **not required** (new advisories must not block unrelated auto-merges, D-006) |

Also: `.github/dependabot.yml` (composer, npm, github-actions; weekly, grouped), `.github/pull_request_template.md`, `.github/CODEOWNERS` (`* @JVillator0`, informational).

**Branch protection:** required status checks are the only protection. No required PR approvals (author and reviewer share one account, 1-03) and no "up to date" requirement (parallel PRs would force rebases). The PR template includes the checklist items "PR title is a Conventional Commit" and "I can explain every line of this PR" (mandatory on money/webhook PRs, see 1-03).

## Acceptance criteria
- [ ] On the empty scaffold, every job of the minimal Gate A set (above) is green and required on `main`; the later jobs are added by the issue that first needs them and then become required.
- [ ] Two representative violations (float in money code; controller using `DB`) fail CI; verified once, recorded in the PR, then reverted. The other rules are covered by named Pest arch tests.
- [ ] `composer check` and `npm run types` pass locally in under 3 minutes.

## Issues
1. Pint config + strict types; Larastan level 8 config.
2. Pest 5 setup + arch test suite + repository tests (`.env.example` covers every `env()` key; max job delay/backoff 900 s for every job; float scan over `config/`).
3. TS strict + ESLint rules + `npm run types`.
4. filacheck script.
5. CI workflow (minimal Gate A jobs + `pr-title`) + Dependabot + PR template + CODEOWNERS + branch protection.
6. `Nanodollars::format()` integer-arithmetic rounding contract + boundary unit test (lands with spec 3-03; the arch rule is in this spec).

## Open questions
- None blocking.
