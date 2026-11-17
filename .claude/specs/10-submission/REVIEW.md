# Reviews · 10-submission

Round 1 · 2026-11-17 · reviewers: workflow agents (Claude Sonnet 5.5), product and technical lenses. Findings are inputs; resolutions are recorded in the spec and in `DECISIONS.md`.

Status legend for resolutions: ☐ open · ☑ applied · ✖ rejected (with reason).


## Product review · `10-submission/SPEC.md` · verdict: **minor changes**

**Strengths**
- 'Review in 5 minutes' with demo accounts and keyword walkthrough is the right priority for an evaluator.
- Trade-offs and 'how this was built' sections make the planning differentiator visible.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | README sections | There is no requirement-to-evidence table. The evaluator's criteria (correct money, robustness, UX, code) and the brief's five required items are not mapped to a URL, a keyword and a file. | Add a table at the top: Requirement \| How to see it \| Where in code \| Test. e.g. Failed = free \| prompt '[fail]' \| VoidUsageAction \| FailedTakeTest. | ☑ applied |
| 2 | important | Data left on the deployed app | It lists takes, a remix chain, an AI change and a purchase, but not which account holds them, or that auditor credentials (MFA-exempt) can reach /admin Reports and Pixelforge usage showing a match. The brief asks to leave a few generations to compare with Pixelforge's records. | Name the demo account holding the data, list the 8 smoke prompts and their expected result (status, charge) in a table, and give the Pixelforge email and key label so numbers can be compared. | ☑ applied |
| 3 | minor | README / wireframes | The wireframes are linked as evidence of planning, but they contain inconsistent figures (see specs 3-03, 6, 7). | Fix before publishing, or label them 'illustrative'. | ☑ applied |
| 4 | minor | Interview prep | Walkthrough covers money and webhook paths but not the UX story that the role is hired for. | Add a 2-minute UI walkthrough: estimate-on-button, waiting-for-capacity copy, Describe a change diff. | ☑ applied |


## Technical review · `10-submission/SPEC.md` · verdict: **minor changes**

**Strengths**
- README structure leads with 'Review in 5 minutes' with demo buttons and suggested keywords, which matches how evaluators work.
- Leaving realistic data (all statuses, a remix chain, an AI change, a purchase) that is reconcilable against Pixelforge's own dashboard and /v1/usage is strong evidence of correct money.
- Interview prep names the money path and a live extension (third provider via the ledger skill, per-project budgets).

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | README sections | There is no mapping from the brief's requirements (estimate, +25%, exact actual cost, failed = free, live history, signed/duplicate/out-of-order webhooks, deployment, admin report matching /v1/usage, balances) to where each is implemented and tested. With 10 specs and many milestones, an evaluator who weighs 'correct money > robustness' and has 22 other submissions has to hunt for it, and the required 2-4 h core can be buried under the extras. | Add a traceability table to the README: brief item -> code path -> Pest test name -> where to see it live (UI/admin screen). Mark each row 'required', 'stretch' or 'extra' so the required core is obviously complete and the rest is clearly additive. | ☑ applied |
| 2 | important | Final checks / post-submission state | D-006's judge auto-merges PRs and Cloud may auto-deploy. If any PR merges or deploys after submission, the evaluated repo and deployed app change under the evaluator, and a late regression in the money path would be disastrous. The submission data check also does not verify that nothing is in flight. | Tag the submission commit (e.g. v1.0-submission), disable auto-merge and auto-deploy, and state the commit in the README. Final check: SQL/command that no usage is open, no take non-terminal, ledger invariant holds and /v1/usage matches for every day with data, with the result and timestamp recorded in the README. | ☑ applied |
| 3 | important | README section 6 (Run locally) / final check 'fresh clone' | A fresh-clone evaluator has no Pixelforge key or Anthropic key and the Pixelforge webhook needs a public URL. If they cannot get a green test run and a working demo quickly, the 'run it yourself' path fails, and D-004 means .env is generated from 1Password, which they cannot use. | Make 'composer check' and the full Pest suite pass offline with no secrets (Http::fake, Agent/Http fake) and put that in CI. Document the minimum local run (own Pixelforge key + tunnel for webhooks; AI feature off via AI_SUGGESTIONS_ENABLED=false). Add a final check 'clone, copy .env.example, composer check green with no keys'. | ☑ applied |
| 4 | minor | Final checks / demo credentials | The auditor account is MFA-exempt and read-only but sees every customer's email and money data, including real evaluators who register on the live app. Credentials travel in the submission form and the demo-login route is public. | Keep seeded data separate from real registrations in the README guidance, rotate or disable the auditor and demo login after the review window, and note this in 'Trade-offs'. | ☑ applied |
| 5 | minor | Trade-offs and what I'd do next | Evaluators value honest limits. Items to name: simulated payments, the account-wide Pixelforge quotas, the Anthropic cost not reconcilable via API, the policy for an orphaned provider success (spec 4 question), polling fallback vs Reverb in production, and rate-card assumptions. | Write them explicitly under 'Trade-offs'. Also state the time actually spent on the required part versus stretch, and that agents were used (it is the visible-process story, so own it). | ☑ applied |
| 6 | minor | Interview prep | The live extension is rehearsed only as ideas. A concrete drill makes the walkthrough credible. | Add a cheap command, php artisan billing:verify (ledger invariant + usage comparison), and rehearse three live changes with the tests that should fail first: change the markup to 30% (config), add a refund adjustment, and add a per-project budget check in TakePolicy. Keep a one-page 'money path file map' (estimate -> hold -> submit -> webhook -> settle -> report) for the walkthrough. | ☑ applied |

**Questions for Javier**
- What is the freeze policy after submission? Options: A. Tag a release, disable auto-merge and auto-deploy, no changes until the interview / B. Keep the harness running and merge only docs/README changes / C. Keep everything live (current). Recommendation: A. Only allow a hotfix path that you do by hand with a documented reason.
