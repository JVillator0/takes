# Takes: product and engineering spec (parent)

Status: **Approved**
Reviews: product ☑ · technical ☑

> Take-home for **PromptEdit, Senior Product Engineer**. Mission of the role: *"Create the most intuitive video editing tool UIs in the world."*
> Brief: https://pixelforge.promptedit.dev/assessment · Provider docs: https://pixelforge.promptedit.dev/docs
> Spec language: English (everything that lands in git/GitHub is English).

## 1. What we are building

**Takes** is an image-generation studio on top of Pixelforge (a simulated, asynchronous image API). It uses the vocabulary of video editing: every generation is a **take**, takes live in **projects**, any take can be **remixed**, and **Describe a change** turns a sentence ("warmer, portrait") into a reviewed parameter diff with a new price.

Customers pay the provider's cost **+ 25%**, from prepaid (simulated) credits. Every provider call (Pixelforge images *and* Anthropic AI suggestions) goes through one **multi-provider ledger**, so the money is exact, reconcilable and explainable.

Required by the brief (must work first): generate page with estimate, cost + 25% with exact actual cost, failed = free, live history, verified/idempotent/out-of-order-safe webhooks, public deployment.
Stretch (after required works): admin report, balances with fake purchases. We add: purchase invoices (simulated, no statements, D-029), the AI feature, and a visible planning/review harness.
Pulled forward (D-019, D-026): the **$1.00 welcome credit** and a **minimal simulated "Buy credits" modal** ship in spec 4, so a newly registered evaluator can generate on the first deploy (registration needs no email verification, D-030). Invoices and the billing page stay in spec 6.

**Evaluator path:** live URL -> Register (or the single "Try a demo account" button, an ephemeral customer with the welcome credit, D-045) -> generate with the welcome credit -> `/admin` with the read-only auditor; the showcase customer (credentials in the README) has real history. Shared demo accounts share Pixelforge's account-wide limits (3 concurrent, 500/day, 60 rpm), so registering is the recommended path; "Waiting for capacity" is expected copy under load (spec 10).

## 2. Principles (apply to every child spec)

1. **Correct money beats everything.** Integer nanodollars, one ledger, exactly-once settlement, failed = free. If a feature threatens this, it waits.
2. **Framework defaults first.** If Laravel, the starter kit or Filament already gives it (auth pages, `users` table, Filament login/MFA, resource list/view layouts), we use it unchanged. We only design and build what is ours.
3. **Explainable over clever.** Every line must survive a live walkthrough and be extendable during the interview.
4. **Design before code.** Every screen has a wireframe; every non-obvious decision has a recorded reason (`DECISIONS.md`).
5. **Totals are exact.** Every total is summed from exact nanodollars and formatted once; never sum displayed (rounded) values (D-021).
6. **Foundations before parallelism.** No parallel agent work until quality gates (Larastan 8, Pest arch tests, TS strict + vue-tsc, CI) and the domain foundation are merged (see §4).
7. **Visible process.** Specs become milestones and issues in a public GitHub Project; every change lands through a PR linked to its issue, reviewed by three specialist agents and a judge.

## 3. Child specs (execution order)

| # | Folder | Spec | Milestone |
|---|---|---|---|
| 0 | `0-repository-and-stack/` | Repository, stack, versions, environments, secrets | M0 Foundations |
| 1 | `1-harness-engineering/` | 01 quality gates & CI · 02 AI harness (Boost, skills, agents) · 03 PR review & auto-merge workflow · 04 parallel work (worktrees, Valet, Sail) · 05 GitHub planning (milestones, issues, Project) | M1 Harness |
| 2 | `2-product-design/` | 01 product & UX principles, IA, copy · 02 public app screens · 03 admin console screens · `wireframes/` | M2 Product design |
| 3 | `3-domain-foundation/` | 01 identities & authorization · 02 schema & take state machine · 03 money & multi-provider ledger · 04 Pixelforge client | M3 Domain foundation |
| 4 | `4-takes-and-generation/` | Projects, composer, estimate, submit, takes grid, take detail, remix, **welcome credit + minimal Buy credits** (D-019, D-026) | M4 Takes |
| 5 | `5-webhooks-and-live-status/` | Signature, idempotency, ordering, reconcile, Reverb + fallback | M5 Webhooks & live |
| 6 | `6-billing-and-invoicing/` | Billing page, full purchase flow, invoices per purchase (no statements, D-029) | M6 Billing |
| 7 | `7-admin-console/` | Filament console: clusters, Shield, ECharts (spiked first, D-061), reports, reconciliation, tables (Filament, Shield, MFA columns and admin roles installed when M7 starts, D-048) | M7 Admin console |
| 8 | `8-ai-describe-a-change/` | Haiku 5.5 suggestions through the same ledger | M8 AI |
| 9 | `9-deployment/` | Laravel Cloud, Pixelforge/Anthropic keys, smoke tests (acceptance split: first deploy 3-5, redeploys 6-8, D-063) | M9 Deploy |
| 10 | `10-submission/` | README, demo accounts, evaluator guide, final checks | M10 Submission |

## 4. Dependency graph and parallelization gates

```
0 ─► 1 ─► 3 ─┬─► 4 ─┬─► 9 (first deploy, required scope) ─┐
     (2 is   ├─► 5 ─┘                                      ├─► 10
   done now) ├─► 6, 7, 8 (stretch, after 4+5) ─► redeploy 9 ┘
             └──────────────────────────────────────────────
```
9 depends on 3, 4 and 5 only (4 includes welcome credit + minimal Buy credits). 6, 7 and 8 land afterwards and trigger redeploys.

- **Gate A (after 0 + 1 merged):** CI is green on an empty app with the minimal set: Larastan level 8, Pint, Pest 5 arch tests and `vue-tsc --noEmit` strict (filacheck joins once `app/Filament` exists, M7). Until then, one writer only (the lead session).
- **Gate B (after 3 merged):** schema, enums, money value object, ledger primitives, the Pixelforge client **and the state-transition actions `ApplyTakeSnapshotAction` and `FailTakeAction` with their tests** exist and are tested (they live in M3, so 4 and 5 only add transport: submit job, webhook, reconcile). Only now the pool starts in parallel (five worktrees, D-070): **4** (a), **5** (b), **7** (c), **6** (d) and **8** (e); 6 and 8 build backend first and rebase on 4 for UI. Inside M3, 3-01, 3-03, 3-04 and the 3-02 actions run in parallel after the 3-02 schema PR.
- **Gate C (after 4 + 5 merged):** deploy (9) first; 6, 7 and 8 continue in d, c and e.
- **Gate D (before 10):** stretch work that is finished is redeployed; unfinished stretch is cut (see below), then 10 closes.
- **Gate E (inside 10, D-071):** a QA agent validates the deployed site against the specs and files issues; `v1.0.0` waits for its exit criteria.
- Required-scope milestones (3, 4, 5, 9) are never blocked by stretch milestones (6, 7, 8).

### Time budget and cut order
The required part is a 2-4 h brief; the harness is a deliberate differentiator but must not delay it.
- **M0 + M1 are capped at one working session.** Minimal Gate A (D-060): Pint, Larastan 8, Pest (+ arch), `vue-tsc`, CI, plus a Conventional Commit PR-title check (D-039). gitleaks, filacheck, audits and dependabot join when first relevant; the coverage gate `--min=85` turns on at Gate B.
- **Minimum that must be live:** 3-04 + 4 + 5 + 9 (generate, webhook, history, deploy). Deploy this thin slice before starting 6, 7 or 8.
- **Cut order if time runs out:** admin tables and charts (7), then invoices (6), then AI polish (8). Never 3, 4, 5, 9.

## 5. Spec lifecycle

`Draft` → `Product review` → `Technical review` → `Questions answered` → `Reviewed` → `Approved` → `Issues created` → `Done`.

- Each child spec has a `REVIEW.md` (at `.claude/specs/<n>-<folder>/REVIEW.md`) with a **product review** (does it serve the customer, the evaluator and the mission?) and a **technical review** (correct, simple, explainable, testable?), both written by reviewer agents and resolved in the spec.
- Every unclear decision is asked to Javier with a multiple-choice question; the answer goes to `DECISIONS.md` and into the spec.
- An approved spec is converted into one GitHub milestone + issues (`spec/<n>` label; the specs live in the repo under `.claude/specs/`, D-041). Issues reference the spec section; PRs close issues.

## 6. Glossary

| Term | Meaning |
|---|---|
| Take | One generation request and its result (Pixelforge `gen_…`). Model `Take`. |
| Project | A customer's folder of takes. |
| Remix | A new take created from an existing one (`parent_take_id`). |
| Suggestion | One *Describe a change* AI call that proposes a parameter diff. |
| Usage record | One billable provider call (Pixelforge or Anthropic) with units, rates snapshot, provider cost and charge. |
| Ledger entry | Append-only **real-money** movement on a customer's balance (`purchase`, `promo_grant`, `charge`, `adjustment`). Holds are not ledger rows (D-024). |
| Hold | State, not a ledger row: `max_charge_nanos` on an open usage record. `available = balance − held` (D-024). |
| Nanodollar | 10⁻⁹ USD, the integer unit for every amount. |
| Billing day | UTC date the provider completed the work (Pixelforge's rule). |

## 7. Files

- `DECISIONS.md`: decision log (what, why, who decided, date).
- `_draft-v0.md`: first single-file draft, kept for history; superseded by the child specs.
- `2-product-design/wireframes/index.html`: wireframe sheets. Public P01–P10. Admin: **A02, A03, A04, A06 are normative** (dashboard, Reports, Reconciliation, take infolist, plus the Adjust credits modal from A05); A07–A08 are documentation and A09–A11 are a column/filter/widget data spec in spec 7, not designs (D-017). Every amount on every sheet comes from **one demo dataset** (D-037). That full-history dataset is seeded only for local, CI and screenshots; in production the showcase customer's history comes from real smoke-test calls and the seeded Demo customer has a $10 purchase and no takes (D-045, spec 9).
- `<n>-<folder>/REVIEW.md`: review rounds per spec; all go in the first commit with `.claude/specs/`.
- Versioning (D-043): `v0.N.0` tag + GitHub Release when each milestone closes; `v1.0.0` only when everything is finished and ready to submit (then the D-031 freeze applies). Commits and PR titles follow Conventional Commits (D-039). The submission README carries the brief-coverage matrix (D-044).
