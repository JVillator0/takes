# 10 · Submission

Status: **Approved** · Milestone: **M10 Submission** · Depends on: 9 · Worktree: main
Reviews: product ☑ · technical ☑ · Decisions: D-030, D-031, D-039, D-042, D-043, D-044, D-045, D-059, D-063

## Goal
An evaluator understands, runs and verifies everything in minutes, and the interview walkthrough is prepared.

## README (English) sections
1. One paragraph: what Takes is and why (mission link).
2. **Brief-coverage matrix** (top of the README, D-044): every requirement, tip and bonus of the brief -> spec -> evidence. Columns: Requirement | Tier (`required` / `stretch` / `extra`) | How to see it live | Code path | Pest test. Rows cover the brief's items: estimate, cost + 25%, exact actual cost, failed = free (`[fail]` -> `VoidUsageAction` -> `FailedTakeTest`), live history, signed / duplicate / out-of-order webhooks, deployment, admin report matching `/v1/usage`, balances. Rows that depend on specs 6-8 are marked as redeploy evidence and are only listed if finished. The required core is visibly complete; the rest is additive.
3. **Review in 5 minutes**: live URL, **register** (welcome credit, no email verification, D-030) as the recommended path, the single **"Try a demo account"** button (ephemeral customer with the welcome credit, D-045), the read-mostly **showcase** customer with real history (credentials listed in the README), the admin auditor via the form, suggested walkthrough with test keywords, what to look at in `/admin`. Note that shared demo accounts share Pixelforge's account-wide limits, so "Waiting for capacity" is expected under load.
4. Money rules (verbatim from spec 3-03 §2–§3; display rules D-021 and D-042: money pages exact, lists/cards/pill 4 decimals), with the worked example; holds are state, not ledger rows (D-024).
5. Webhooks: signature, idempotency, ordering, reconcile, `orphan_success` (D-025), delivery log (D-027); where the evidence is (A06, A04).
6. Architecture map: Actions/Queries/DTOs/Enums, the ledger diagram, state machine diagram, and a one-page **money path file map** (estimate -> hold -> submit -> webhook -> settle -> report).
7. Run locally: Valet and Sail; `.env` from `.env.example`. Minimum: own Pixelforge key (tunnel optional, reconcile polling works), `AI_SUGGESTIONS_ENABLED=false` without Anthropic. `composer check` and the Pest suite pass offline with no keys.
8. Quality: `composer check`, `npm run types`, CI badges, coverage.
9. **How this was built**: agents were used; specs -> Project board -> PRs with 3 reviewers + judge; links to the Project, a few representative PRs, the wireframes (labelled illustrative where volumes are not real). State the time spent on the required part versus stretch.
10. **Trade-offs and what I'd do next**: simulated payments; account-wide Pixelforge quotas; Anthropic cost not reconcilable via API; orphaned provider success policy (D-025); polling fallback vs Reverb; scale-to-zero cold starts (D-038); rate-card assumptions; auditor and demo login rotated or disabled after the review window, and seeded data kept separate from real registrations.

## Data left on the deployed app
Held by the **showcase** customer (spec 9; real data from real Pixelforge calls, never the seeded dataset): first-deploy evidence: one take per smoke prompt (normal, `[succeed]`, `[fail]`, `[duplicate] [succeed]`, `[slow]`, `[blocked]`, `[overloaded]`), one remix chain; redeploy evidence (6-8): one AI change, one purchase with invoice. The README lists each prompt with its expected status and charge, the Pixelforge account email and key label, so the numbers can be compared with Pixelforge's dashboard and `/v1/usage`. After redeploy (7), the auditor (MFA-exempt, read-only) can open `/admin` Reports and the Pixelforge usage page, which shows a match for each day with data.

## Release freeze (D-031)
Tag `v1.0.0` on the submission commit (earlier milestones were tagged `v0.N.0`, D-043) and state it in the README. Then disable auto-merge (judge) and Cloud auto-deploy until the interview; only manual hotfixes, each with a documented reason.

## Final checks
- [ ] Fresh clone + Sail run per README on a clean machine; clone, copy `.env.example`, `composer check` green with no keys.
- [ ] All milestones closed or explicitly deferred with a note.
- [ ] No secrets in history (gitleaks), repo public, Project public.
- [ ] Data check recorded in the README with timestamp: no usage open, no take non-terminal, ledger invariant holds, `/v1/usage` matches for every day with data (`php artisan billing:verify --usage`, owned by 3-03, D-059).
- [ ] Tag `v1.0.0` created, GitHub Release notes generated from Conventional Commits (D-039), auto-merge and auto-deploy disabled.
- [ ] Submission form: repo URL, app URL, Pixelforge account email, admin auditor credentials (Javier submits).

## Interview prep (not in the repo)
- 10-minute walkthrough script: money path (estimate -> hold -> settle), webhook path, plus a **2-minute UI walkthrough** (estimate on the button, "Waiting for capacity" copy, Describe a change diff).
- Rehearsed live changes, each with the test that should fail first: markup to 30% (config), add a refund adjustment, a per-project budget check in `TakePolicy`; also adding a third provider via the `money-invariants` skill.

## Issues
1. README (evidence table, sections, trade-offs).
2. Run `billing:verify` (built in 3-03) for the final data review and record the result.
3. Release freeze and submission checklist.
