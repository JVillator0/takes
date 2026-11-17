# 10 · Submission

Status: **Approved** · Milestone: **M10 Submission** · Depends on: 9 · Worktree: main
Reviews: product ☑ · technical ☑ · Decisions: D-030, D-031, D-039, D-042, D-043, D-044, D-045, D-059, D-063, D-071

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
Held by the **showcase** customer (spec 9; real data from real Pixelforge calls, never the seeded dataset): first-deploy evidence: one take per smoke prompt (normal, `[succeed]`, `[fail]`, `[duplicate] [succeed]`, `[slow]`, `[blocked]`, `[overloaded]`), one remix chain; redeploy evidence (6-8): one AI change, one purchase with invoice. The README lists each prompt with its expected status and charge, the key label (the Pixelforge account email goes only in the submission form, D-081), so the numbers can be compared with Pixelforge's dashboard and `/v1/usage`. After redeploy (7), the auditor (MFA-exempt, read-only) can open `/admin` Reports and the Pixelforge usage page, which shows a match for each day with data.

## QA validation (final step before the release, D-071)
Runs after Gate D, on the production deploy (spec 9): it covers the required scope (3, 4, 5, 9) plus whatever stretch work (6, 7, 8) shipped; acceptance criteria of cut specs are out of the plan. The release freeze and `v1.0.0` come only after it passes.

**Who and how.** A dedicated QA agent (Opus 5.5, `.claude/agents/qa-tester.md`) drives a real browser against the **production URL** (Claude in Chrome so Javier can watch, Playwright headless as fallback), at desktop width and at 390 px (P10). It never reads the code to decide what "should" happen: the test plan is the specs. It registers its own customer (welcome credit), uses "Try a demo account", the showcase customer and the admin auditor.

**Test plan** (generated from the specs, committed as `.claude/qa/plan.md` before the run):
- Every acceptance criterion of specs 3-9 that is observable from the browser or the public webhook endpoint, plus the brief-coverage matrix rows (D-044).
- Every customer-facing string against the 2-01 copy tables (states, failures, denials, billing, AI); no provider names, tokens, cost or margin shown to customers (D-023); money formats (D-021, D-042).
- Each public and normative admin screen against its wireframe (P01-P10, A02-A04, A06): layout, information shown, states.
- Scenario keywords (`[succeed]`, `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]`): the take reaches the documented state and charge (failed = $0.00), the balance and held amounts move as specified, and the live status updates without a reload (and with the socket blocked, via polling).
- Accessibility smoke (keyboard path through composer and drawer, focus visible, status not by color alone) and console errors.
- **Budget:** at most 25 Pixelforge generations and 5 AI suggestions for the first full pass, plus at most 10 generations and 2 suggestions for all re-verification runs together (account-wide limits are shared with evaluators).

**Issues.** Each defect becomes one GitHub issue: title `[QA] <screen>: <what is wrong>`, labels `qa`, `type:fix`, `spec/<n>`, severity `qa:blocker|qa:major|qa:minor`, milestone M10; body = steps to reproduce, expected (quoting the spec line or copy row, with link), actual, environment (URL, viewport, account type), screenshot (uploaded to the issue) and console output if any. Duplicates are checked against open `qa` issues first. Copy or layout nits are batched into one issue per screen. **Redaction (public repo):** no passwords, API keys, tokens, session cookies, signed/temporary URLs or another person's personal data in issue text, console excerpts or screenshots; crop or blur before uploading, and refer to accounts by role ("QA customer", "auditor").

**Exit criteria.** No open `qa:blocker` or `qa:major`; each `qa:minor` is fixed or explicitly deferred in the README trade-offs. Fixes land as normal PRs (`Closes #N`) through the review workflow; after merge and redeploy the QA agent re-runs the affected checks and closes the issue with evidence. The final QA report (checks run, pass/fail counts, issues opened/closed, timestamp) is linked from the README "How this was built" section. The final data check below (`billing:verify --usage`) runs **after** the last QA re-verification and redeploy, so its recorded timestamp covers the data QA created.

## Release freeze (D-031)
Tag `v1.0.0` on the submission commit (earlier milestones were tagged `v0.N.0`, D-043) and state it in the README. Then disable auto-merge (judge) and Cloud auto-deploy until the interview; only manual hotfixes, each with a documented reason.

## Final checks
- [ ] Fresh clone + Sail run per README on a clean machine; clone, copy `.env.example`, `composer check` green with no keys.
- [ ] All milestones closed or explicitly deferred with a note.
- [ ] No secrets in history (gitleaks), repo public, Project public.
- [ ] Data check recorded in the README with timestamp: no usage open, no take non-terminal, ledger invariant holds, `/v1/usage` matches for every day with data (`php artisan billing:verify --usage`, owned by 3-03, D-059).
- [ ] QA validation passed (exit criteria above, D-071) and its report is linked from the README.
- [ ] Tag `v1.0.0` created, GitHub Release notes generated from Conventional Commits (D-039), auto-merge and auto-deploy disabled.
- [ ] Submission form: repo URL, app URL, Pixelforge account email, admin auditor credentials (Javier submits).

## Interview prep (not in the repo)
- 10-minute walkthrough script: money path (estimate -> hold -> settle), webhook path, plus a **2-minute UI walkthrough** (estimate on the button, "Waiting for capacity" copy, Describe a change diff).
- Rehearsed live changes, each with the test that should fail first: markup to 30% (config), add a refund adjustment, a per-project budget check in `TakePolicy`; also adding a third provider via the `money-invariants` skill.

## Issues
1. README (evidence table, sections, trade-offs).
2. Run `billing:verify` (built in 3-03) for the final data review and record the result.
3. Release freeze and submission checklist.
4. QA agent definition (`qa-tester`) + test plan generated from the specs (`.claude/qa/plan.md`).
5. QA run on production: file `qa` issues, re-verify fixes, publish the QA report (D-071).
