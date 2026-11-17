# 1-02 · AI harness: context, skills, agents

Status: **Approved** · Milestone: **M1 Harness** · Depends on: 1-01 · Unblocks: 1-03, Gate A · Parallel: no
Reviews: product ☑ · technical ☑

## Goal
Any Claude Code session (lead or worktree agent) starts with the project's rules, the right skills and the specialist reviewers, so output is consistent without re-explaining.

## Context files (committed)
```
.claude/specs/                   the specs (D-041), read by agents like any other context; mirrored as milestones/issues in the GitHub Project
CLAUDE.md                        overview, commands, invariants (money, webhooks, auth), + Boost block
.ai/guidelines/*.md              project guidelines merged by Boost into CLAUDE.md
.ai/rules/                       Boost project rules (record-rule tool), committed
.claude/settings.json            hooks (below)
.claude/rules/architecture.md    Actions/Queries/DTOs/Enums/Exceptions (ported from templates/laravel-filament)
.claude/rules/money.md           spec 3-03 invariants as a checklist (below)
.claude/rules/webhooks.md        spec 5 invariants as a checklist (below)
.claude/rules/frontend.md        Inertia v3 + Vue + TS conventions, money as server strings; copy has one source (D-057): the state, failure, denial-reason, billing and AI strings in 2-01, which feed `lang/en/*.php`; other specs reference them and never restate strings
.claude/rules/filament.md        cluster layout, Tables/Schemas/Actions/Pages split, navigation builder
.claude/worktree-pool.md         pool definition (spec 1-04)
.mcp.json, boost.json            Laravel Boost MCP (no secrets)
```

`.claude/rules/money.md` and `webhooks.md` are written verbatim as checklists; every rule names its test, and reviewer prompts reference the files instead of paraphrasing them. Required entries: lock order **take|suggestion → customer; usage is re-read under the customer lock, never locked separately**; take transitions only inside `ApplyTakeSnapshotAction`; settlement only inside a terminal transition (exactly once); no HTTP call inside a DB transaction; no queue delay above 900 s; holds are state, not ledger rows (D-024); customer money is exact, trailing zeros trimmed but never fewer than 2 decimals (e.g. $5.00, $0.0735); rounded display (lists, cards, pill) never shows 2 decimals (D-042); customer views never expose provider cost, tokens, provider names or margin (D-023); webhook signature checked on the raw body (invalid, missing or stale ⇒ 401, nothing written, D-056), `event_id` recorded once plus a `webhook_deliveries` row per delivery (D-027).

The README gets a one-paragraph "what each harness file is for".

## Skills
| Skill | Source | Committed? |
|---|---|---|
| `inertia-vue-development`, `pest-testing`, `tailwindcss-development`, `wayfinder-development`, `infer-conventions` | Laravel Boost (`boost:install`) | yes |
| `modern-web-guidance` | `npx modern-web-guidance@latest install` (GoogleChrome) | yes (MIT/Apache, public) |
| `filament-blueprint` | Filament Blueprint (licensed) | **no**: local-only, git-ignored (D-003) |
| `agent-worktrees` | copied from `VillaSoftware/Buxy/.claude/skills/agent-worktrees`, adapted to Valet | yes |
| `pr-review` | this project: the review + judge procedure (spec 1-03) | yes |
| `money-invariants` | this project: how to add a billable provider (spec 3-03 §8) | yes; written just before first use (M3) |

Blueprint workflow: installed in the main checkout only (`composer require --dev filament/blueprint` without committing, or in a local `composer.local.json`), used to plan Filament work; its plans are committed under `docs/blueprints/` as evidence.

## Agents (`.claude/agents/`, all **Opus 5.5**)
| Agent | Scope (changed paths) | Checks |
|---|---|---|
| `laravel-backend-reviewer` | `app/` except `app/Filament`, `database/`, `routes/`, `config/`, `tests/` | Architecture rules; the checklists in `.claude/rules/money.md` and `webhooks.md` (rejects each listed violation); idempotency, locks, `auth:customer` guard (D-032), migrations reversible + constraints, Larastan-friendly types, test quality. Based on `~/.claude/agents/mr-review-laravel.md`. |
| `filament-reviewer` | `app/Filament/`, `app/Providers/Filament/`, `lang/*/console.php` | Filament 5 APIs, clusters + navigation builder, Shield permissions on every resource/page/action, query efficiency (withCount/withSum, no N+1), no polling in admin widgets (`$pollingInterval = null`, D-038), filacheck rules, audit of write actions. |
| `inertia-vue-reviewer` | `resources/js/`, `resources/css/`, `vite.config.*` | Composition API + strict TS, Inertia v3 patterns (forms, partial reloads, polling), Echo lifecycle, accessibility, money/status/error copy vs the 2-01 copy tables (state, failure, denial, billing, AI) and `lang/en/*.php` (not the PNGs), amounts only as server strings, modern-web-guidance patterns. |
| `pr-judge` | whole PR | Scores, gates, verdict, merge (spec 1-03). |

Each reviewer returns structured findings: `{path, line, severity: critical|important|minor, title, body, suggestion?}`.

## Hooks (`.claude/settings.json`)
- PostToolUse `Edit|Write` on `*.php` → `vendor/bin/pint <file>`; on `*.vue|*.ts` → `npx prettier --write <file>`.
- Stop → only when PHP files under `app/` changed: full `composer stan` with result cache (`tmpDir`) and a 60 s timeout, non-blocking hint. CI is the truth.

## Acceptance criteria
- [ ] A fresh session in any worktree lists the skills and agents above.
- [ ] `boost:update` doesn't overwrite our content outside the Boost block.
- [ ] No licensed file is tracked (`git ls-files | grep -i blueprint` returns only `docs/blueprints/` plans).

## Issues
1. CLAUDE.md + `.ai/guidelines` + `.claude/rules/*`.
2. Boost install + modern-web-guidance install.
3. Port `agent-worktrees` skill; write `pr-review` skill (`money-invariants` just before M3 use).
4. Agent definitions, in order: `laravel-backend-reviewer`, `inertia-vue-reviewer`, `pr-judge` first; `filament-reviewer` just before M7. Rules and reviewer agents land before optional skills (`modern-web-guidance`).
5. Hooks.

## Open questions
- None blocking.
