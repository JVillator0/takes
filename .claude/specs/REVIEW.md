# Reviews · parent spec (README)

Round 1 · 2026-11-17 · reviewers: workflow agents (Claude Sonnet 5.5), product and technical lenses. Findings are inputs; resolutions are recorded in the spec and in `DECISIONS.md`.

Status legend for resolutions: ☐ open · ☑ applied · ✖ rejected (with reason).


## Product review · `README.md` · verdict: **minor changes**

**Strengths**
- Clear separation of required vs stretch vs our extras, with explicit principle 'correct money beats everything'.
- Glossary and the 'billing day = UTC completion day' definition make the money story explainable in the interview.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | §4 Gate D / §1 'Required by the brief' | Gate D says deploy (9) happens after 6 (billing) is merged, but the closing bullet and spec 9 say required milestones are never blocked by stretch ones and 9 depends only on 4 and 5. The README also lists 'balances with fake purchases' as stretch while the required path (spec 4 CreateTakeAction, spec 3-03 OpenUsageAction) already refuses to generate without credit. A customer-facing path to get credit (welcome credit, Buy credits) therefore has to exist before the first deploy, yet it lives in spec 6. | Fix the graph so 9 depends on 4+5+'minimal credits' (welcome credit grant + a bare Buy credits action), and say explicitly which part of spec 6 is pulled forward. Delete the 'after 6 merged' wording from Gate D. | ☑ applied |
| 2 | minor | §7 Files / wireframes | The wireframe sheets are described as the source of truth for amounts, but several admin and public sheets disagree with each other and with the specs (see findings on specs 3-03, 5, 6 and 7). There is no single seeded dataset behind them. | Add a rule: every number on a sheet comes from one fixture (one customer, one month) and a short 'wireframe data' table in 2-product-design; fix the inconsistencies before issues are created. | ☑ applied |


## Product review · `README.md` · verdict: **minor changes**

**Strengths**
- Clear separation of required (3,4,5,9) vs stretch (6,7,8), with gates that make the money foundation precede parallel work.
- Principles 1 (money first) and 2 (framework defaults) match the evaluation order and keep the live walkthrough explainable.
- Mission link is concrete: take/remix/version vocabulary and 'Describe a change' map to 'intuitive editing UIs' rather than being decoration.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | §2 principle 5, §4 graph and Gate D vs 6-billing §Welcome credit vs 9-deployment | The text says required milestones (3,4,5,9) are never blocked by stretch (6,7,8), but the graph and Gate D put deploy (9) after 6 and 8. 9-SPEC says it depends only on 4 and 5. Separately, the welcome credit (D-019) is built in M6, so a required-scope deploy has no way to give a newly registered evaluator any credits and they cannot generate. The header 'Buy credits' button (P03/P04) also does nothing until M6. | Redraw the graph so 9 depends on 4+5 and re-deploys after 6/7/8. Move the welcome-credit grant and the demo-account seeders (3-01) into M4 or M3, and hide 'Buy credits' until M6 ships (or link it to the billing page empty state). | ☑ applied |
| 2 | important | §3 table / §4 | Eleven milestones, five harness specs and a three-reviewer PR workflow are planned for a 2-4 h required part. There is no time budget and no explicit 'cut line'. The evaluator sees a walking skeleton only after M0, M1, M3 and M4 are merged, which is the main schedule risk. | Add a short 'Time budget and cut order' section: cap M0+M1 at one working session (Gate A minimal = Pint, Larastan, Pest, vue-tsc, CI; filacheck, gitleaks, coverage threshold and dependabot are added when first needed), and define the cut order if time runs out (statements, then invoices, then AI polish, then admin tables), never touching 3/4/5/9. | ☑ applied |
| 3 | important | §7 Files and §2 principle 2, D-017 | The README still says wireframes are 'A02-A11 admin'. Per the settled scope, admin wireframes are only for the custom pieces: dashboard with widgets, Reports, Reconciliation and the take infolist. A05 (customer view), A07 (IA), A08 (authorization) and the A09-A11 table sheets are standard list/view or documentation, but the table and widget information still matters. | State in §7 that A02, A03, A04, A06 are the normative admin wireframes (plus the Adjust credits modal from A05). A07-A08 become documentation and A09-A11 become a column/filter/widget data spec in spec 7, not designs. | ☑ applied |
| 4 | important | §1 and D-022 (proposed) | Invoicing and monthly statements are added scope that the brief does not ask for. P09's statement does not foot with admin credit adjustments or holds, and its numbers do not match P08/A05 (see 2-02). It is the part most likely to show visible inconsistencies to an evaluator and cost interview time. | Keep purchase invoices (small: one table, one counter row, one printable view) and drop or defer the monthly statement. Show the provider cost + 25% breakdown inside the take detail and billing activity instead. | ☑ applied |
| 5 | minor | §3 and spec 10 | The evaluator's first 5 minutes are only described in spec 10. The README (spec) does not say which URL or account they land on, and the shared demo accounts hit the single Pixelforge account limits (3 concurrent, 500/day, 60 rpm), so two evaluators at once will see 'Waiting for capacity'. | Add the evaluator path to §1 (one line) and see the question on demo accounts in 3-01. | ☑ applied |

**Questions for Javier**
- How much of invoicing should ship (D-022)? Options: A. Purchase invoices only (gapless number, printable page), no monthly statement / B. Invoices + monthly statements as drawn in P09 / C. No invoices; purchases appear only in the ledger/activity. Recommendation: A. It shows an auditable money-in path with little code, while statements add reconciliation edge cases (adjustments, holds) for no brief requirement.


## Technical review · `README.md` · verdict: **minor changes**

**Strengths**
- Principles are sharp and map directly to the evaluation order (money, then robustness, then UX, then code).
- Required and stretch scope are separated, and required milestones are never blocked by stretch ones.
- Glossary and ledger entry types are consistent with 3-03.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | §4 Gate B / parallel plan | Worktrees a (spec 4 Takes) and b (spec 5 Webhooks) are meant to run in parallel, but spec 4's submit path needs ApplyTakeSnapshotAction and FailTakeAction. Spec 5 owns the first and spec 4 owns the second. 3-02 says all state transitions happen only in those two actions. So a cannot finish without b's code, and the Gate B parallelism is partly fictional. | Move ApplyTakeSnapshotAction, FailTakeAction and their tests into M3 (3-02 or 3-03). Then Gate B really means the domain is complete, and 4 and 5 only add transport (submit job, webhook, reconcile). | ☑ applied |
| 2 | important | §3-§4 overall scope vs 2-4 h brief | Before any required feature exists, the plan puts the whole harness (M1), the domain foundation (M3) and Gates A and B ahead of it. The take-home asks for 2-4 h of required work including deployment. The process is a deliberate differentiator, but nothing says how it degrades if time runs out. | Add a cut line to the README: after M3, build the thinnest vertical slice of 4+5+9 (generate, webhook, history, deploy) and deploy it before starting 6, 7 or 8. Say explicitly that 3-04 + 4 + 5 + 9 are the minimum that must be live. | ☑ applied |
| 3 | minor | §1 / D-021 | The README says 'failed = free' and 'exact actual cost'. Per-row amounts shown at 4 decimals will not sum to the exact total shown elsewhere, for example in statements and the admin report. | State in the README principles that every total is summed from exact nanodollars and formatted once. Never sum displayed values. | ☑ applied |
| 4 | minor | §5 Spec lifecycle | The lifecycle requires a REVIEW.md per spec, but the repo tree in spec 0 does not mention it. The first commit says 'spec/ with _draft-v0.md removed', which removes the history of the planning. | Keep _draft-v0.md in git history only. Say where each REVIEW.md lives (spec/<n>/REVIEW.md) and that those files go in the first commit. | ☑ applied |
