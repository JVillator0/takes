# Reviews · 1-harness-engineering

Round 1 · 2026-11-17 · reviewers: workflow agents (Claude Sonnet 5.5), product and technical lenses. Findings are inputs; resolutions are recorded in the spec and in `DECISIONS.md`.

Status legend for resolutions: ☐ open · ☑ applied · ✖ rejected (with reason).


## Product review · `1-harness-engineering/01-SPEC.md` · verdict: **minor changes**

**Strengths**
- Arch tests encode the money rules mechanically (no float/round/number_format in money namespaces), which is exactly what the evaluator weights most and makes the interview walk-through easy.
- Money arrives in Vue as server-formatted strings, which prevents UI amounts from disagreeing with the ledger.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | CI table and Acceptance criteria | CI has five required jobs including coverage min 85, gitleaks, npm audit, filacheck and a sail config job, all required before any feature. A single flaky or irrelevant gate (npm audit on a transitive dependency, coverage on scaffold code) can block merges during a 2-4 h exercise. | Mark audit and gitleaks as required but non-blocking until M9 (or pin their config), enable the coverage threshold only after M3, and add filacheck when Filament lands. | ☑ applied |
| 2 | minor | Acceptance criteria | 'A deliberate violation of each arch rule fails CI (verified once)' is ~9 rules and is a time sink with low evaluator value. | Verify two representative rules (float in money code, controller using DB) and record that in the PR; the rest are covered by the Pest arch test names. | ☑ applied |
| 3 | minor | PHP rules | The 'no round(' source scan includes App\Actions\Takes, but the estimator rounding for display (D-021) lives in Support\Money; this is fine, but the rule does not state where display rounding is allowed, so a contributor may be confused. | Add one sentence: display rounding exists only in Nanodollars::format(). | ☑ applied |


## Product review · `1-harness-engineering/02-SPEC.md` · verdict: **minor changes**

**Strengths**
- Rules are split by topic (money, webhooks, frontend, filament) so agents load only what they need.
- Licensed Blueprint content is kept out of git but its plans are committed as evidence, which gives visible planning without breaking D-003.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Agents table: inertia-vue-reviewer | The reviewer is supposed to check 'money/status/error copy vs wireframes', but the wireframes currently disagree with each other on money (e.g. on-hold amounts, 2 vs 4 decimals) and there is no single copy catalog. The agent will produce noise or approve inconsistent copy. | Add a copy source of truth (e.g. lang/en/takes.php plus a status/money state table from 2-01) and have the reviewer compare against it instead of the PNGs. | ☑ applied |
| 2 | minor | Hooks | Stop hook runs composer stan on changed files on every session stop. Larastan level 8 with Filament can take tens of seconds, slowing every interaction with little benefit. | Run it only when PHP files under app/ changed and cap it with a timeout; otherwise leave it to CI. | ☑ applied |
| 3 | minor | Issues list | Four agent definitions, six skills and three rule sets are all written up front. Some (filament-reviewer, money-invariants) are not exercised until M6-M7. | Write the filament-reviewer and money-invariants skill just before their first use; the evaluator sees the same planning trail. | ☑ applied |


## Product review · `1-harness-engineering/03-SPEC.md` · verdict: **minor changes**

**Strengths**
- The scorecard comment, labels and verdicts live in public PRs, so the harness is visible evidence for the evaluator.
- 'Money safety' and 'Robustness' dimensions mirror the evaluation order, and hard gates reject floats in money paths.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Flow step 3 and Verdict handling | Every PR (including docs and chore PRs) runs up to four Opus agents, with up to 3 rounds. During the 2-4 h required window this adds waiting time per PR, and auto-merge on avg >= 8.0 can merge something the author does not fully understand, which conflicts with the interview requirement to explain every line. | Skip the full workflow for docs-only or generated-file PRs (judge-only), and require the author's explicit 'I can explain this' checklist item on money/webhook PRs. | ☑ applied |
| 2 | minor | Judge rubric: UX vs wireframes | The UX dimension is graded 'vs wireframes' but the wireframes are labelled as not final for the admin (list/view resources are standard). | Limit the UX-vs-wireframe check to the custom admin pages (dashboard, Reports, Reconciliation, take infolist) and the public screens. | ☑ applied |
| 3 | minor | Acceptance criteria | A seeded bad PR (float in money code) is a good demo but it costs a full review run. | Keep it as a single closed PR linked from the README 'How this was built' section. | ☑ applied |


## Product review · `1-harness-engineering/04-SPEC.md` · verdict: **minor changes**

**Strengths**
- Per-worktree DB, ports and webhook rules are explicit; routing webhooks only to main and letting worktrees prove the polling fallback is a neat consequence.
- Sail is kept as the evaluator path.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Pool / Which work runs where | Three parallel agents against one Pixelforge account share its limits (60 rpm, 3 concurrent generations, 500/day across all keys). Parallel worktrees running real submit tests will trigger 429s and burn the daily budget that the deployed demo and the evaluators also need. | Add a rule: worktrees use fakes (Http::fake / signed fixtures) by default; only main talks to Pixelforge, and only one manual smoke at a time. | ☑ applied |
| 2 | minor | Webhooks locally | ngrok URL changes on restart unless a reserved domain is used; the local key's webhook URL then breaks silently. | Note a stable ngrok domain or the manual step to update the local webhook URL. | ☑ applied |


## Product review · `1-harness-engineering/05-SPEC.md` · verdict: **ready**

**Strengths**
- Specs to milestones to issues to PRs mapping is simple, scripted and idempotent, and directly delivers the 'visible planning' differentiator.
- Required/stretch label lets an evaluator see priorities at a glance.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | minor | Mapping / Project views | Creating every issue up front (roughly 60+) means the board will contain many items that change shape after Gate B, and stale issues look like sloppy planning. | Create issues for M0-M5 up front and for M6-M10 right before Gate C, but keep all milestones visible. | ☑ applied |
| 2 | minor | Project fields | Seven fields (Status, Milestone, Spec, Worktree, Size, Required/Stretch, plus labels) duplicate information. | Drop 'Spec' and 'Milestone' (labels and milestone already carry it) and keep Status, Worktree, Size, Required/Stretch. | ☑ applied |


## Technical review · `1-harness-engineering/01-SPEC.md` · verdict: **minor changes**

**Strengths**
- Gates exist before feature code, and 'deliberate violation fails CI' is a good acceptance test.
- Arch tests encode money rules (no float, no round) mechanically.
- Money as server-formatted strings avoids JS number issues.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | PHP rules (arch tests) vs 3-03 §1 | The arch scan forbids `round(`, `number_format(` and `float` inside App\Support\Money. 3-03 puts Nanodollars::format(decimals, RoundingMode) in exactly that namespace. Either the arch test fails on the first PR or the display rounding gets implemented by dodging the scan. | Specify that format() is implemented with integer arithmetic (intdiv and remainder, half-up as +half then intdiv). Keep the forbid-list as written. Add a unit test for display rounding at the boundaries (x.49999, x.5). | ☑ applied |
| 2 | important | CI / security job | `composer audit` and `npm audit --audit-level=high` as required checks fail on advisories published after the PR was opened, which blocks unrelated auto-merges. With judge auto-merge this can stall the pipeline at a bad moment. | Run audits on a schedule plus on dependency-file changes, or mark them non-required. Keep gitleaks required. | ☑ applied |
| 3 | minor | CI: branch protection | CODEOWNERS `* @JVillator0` combined with required reviews would block the judge's merge, since the PR author and the reviewer are the same account (see 1-03 §5). 'Require branches up to date' would force rebases on every parallel PR. | State that required status checks are the only protection. Do not require PR approvals and do not require up-to-date branches. CODEOWNERS stays informational. | ☑ applied |
| 4 | minor | CI: test job | `pest --parallel --coverage --min=85` runs paratest with one database per process. The Postgres role needs CREATEDB, and concurrency tests (3-03) cannot run inside RefreshDatabase transactions. | Document that the role is a superuser in CI. Tag real-concurrency tests with a Pest group that uses DatabaseMigrations and runs serially, and keep them out of the parallel run. | ☑ applied |
| 5 | minor | CI jobs | filacheck, the Sail compose check and Filament lint run on an empty scaffold where nothing exercises them, and there is a whole job for `docker compose config`. It is cheap, but the `sail` job adds noise for little signal. | Fold `docker compose config` into the `lint` job, and make filacheck skip if app/Filament does not exist yet. | ☑ applied |


## Technical review · `1-harness-engineering/02-SPEC.md` · verdict: **minor changes**

**Strengths**
- Reviewer scopes are clearly partitioned by path.
- Licensed Blueprint content stays out of git and its plans are committed as evidence.
- Structured finding schema makes the judge's job mechanical.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Agents: filament-reviewer | The checklist says 'no widget polling'. But the money-correctness widget in the admin (A10) and reconcile 'Needs attention' views are naturally polled, and spec 7 may need it. An absolute rule will create a false critical finding that the judge treats as blocking. | Make it 'no polling faster than 30 s and never on pages with heavy aggregates'. Check the wording against spec 7 before approving. | ☑ applied |
| 2 | important | Agents: laravel-backend-reviewer | The 'money invariants, idempotency, locks' checklist is generic. It does not list the specific invariants the reviewer should reject: lock order take → customer → usage, a transition outside ApplyTakeSnapshotAction, settlement outside a terminal transition, any Http call inside a DB transaction, and any queue delay above 900 s. | Write these as a checklist in .claude/rules/money.md and webhooks.md and reference the files from the agent prompts. Do not paraphrase them. Each rule should name its test. | ☑ applied |
| 3 | minor | Hooks | A Stop hook that runs `composer stan` on changed files is non-blocking by design, but Larastan at level 8 on a partial file list gives different results than a full run (missing baseline context and dynamic Eloquent resolution). | Run full `composer stan` with result caching enabled (tmpDir), or accept the partial run as only a hint. CI stays the truth. | ☑ applied |
| 4 | minor | Scope | Six skills, four agents, four rule files and hooks is a lot of setup before Gate A for a 2-4 h task. The interview will ask what each one does. | Order the issues so that CLAUDE.md, rules and reviewer agents land first. Skills such as modern-web-guidance can come later. Keep a one-paragraph 'what each file is for' in the README. | ☑ applied |


## Technical review · `1-harness-engineering/03-SPEC.md` · verdict: **minor changes**

**Strengths**
- Hard gates map onto the evaluation priorities (money safety and robustness have their own scores).
- Judge rubric and the findings numbering are concrete and demonstrable.
- The no-force-push, reply-per-thread rule makes the history legible.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Flow step 3 and 4 | The review workflow runs from a local Claude Code session (.claude/workflows/pr-review.js), not from GitHub Actions. Auto-merge only happens when that session runs. If CI turns green after the judge's run, or the judge merges on a red/pending check, the 'CI green' gate depends on timing. | The judge must re-read `gh pr checks` immediately before merge and use `gh pr merge --squash --auto` so GitHub itself waits for required checks. Record the commit SHA reviewed and refuse to merge if the head SHA changed. | ☑ applied |
| 2 | important | Verdict rules | Averages with N/A excluded let a PR with strong Clarity and Tests scores hide a weak Money safety score (avg ≥ 8.0 with one 5). The hard gates list only 'no float' and 'no unresolved critical'. | Add a floor: any applicable Money safety or Robustness score below 7 ⇒ NOT MERGEABLE regardless of the average. | ☑ applied |
| 3 | minor | Cost and time | Three Opus reviewers plus a judge on every PR, up to 3 rounds, over ~40 issues is a lot of tokens and wall-clock. D-006 is settled, so this is not a challenge to the decision. | Skip reviewers with no matching paths (already stated) and let docs/chore PRs run the judge only. Cap the diff given to each reviewer to its own paths. | ☑ applied |
| 4 | minor | Acceptance criteria | 'PR #1 itself goes through the workflow' is circular: the workflow script lands in PR #1 and cannot review it before it exists on main. | PR #1 is reviewed by running the script from the branch. Say so, or make PR #1 only the scaffold and run the workflow first on PR #2. | ☑ applied |


## Technical review · `1-harness-engineering/04-SPEC.md` · verdict: **minor changes**

**Strengths**
- Per-worktree DB, ports and test DB are fully specified.
- Choosing to test webhooks with signed fixtures and let worktrees use reconcile also proves the polling fallback.
- Sail compatibility is kept as an acceptance criterion.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Webhooks locally / pool | Pixelforge limits are per account: 60 req/min, 3 generations in flight and 500 per day. Three worktrees plus the main checkout plus the deployed app share the same limits if the same account is used. Parallel agents running manual or e2e generations will hit concurrency_limit_exceeded and daily_limit_exceeded, and that looks like an app bug. | Rule: worktrees use Http::fake in tests and call the real API only for manual checks, one worktree at a time. Add a note to worktree-pool.md. Consider a LOCAL_PIXELFORGE_THROTTLE guard in the client for dev. | ☑ applied |
| 2 | minor | Webhooks locally | A free ngrok tunnel URL changes on restart and the Pixelforge webhook URL must be re-registered each time. Dedup and replay by dashboard 'resend events' are the real tools here. | Use a reserved ngrok domain or a Cloudflare tunnel with a fixed name. Document in the README that resent events from the dashboard are the demo path. | ☑ applied |
| 3 | minor | Pool | worktree-env.sh copies secrets from the main .env into three more files, which extends where API keys live on disk. | Have the script call `op read` itself (D-004) instead of copying, and give worktrees the same local key only if a worktree really needs real API access. | ☑ applied |


## Technical review · `1-harness-engineering/05-SPEC.md` · verdict: **ready**

**Strengths**
- Spec to milestone to issue to PR chain is simple and scriptable.
- The script is idempotent and creates the project views that show parallelism.
- Branch naming and Closes #N make the evidence verifiable.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | minor | Creation script | `plan-to-github.sh` parses issue lists from markdown. A single wording change in a spec silently produces duplicate or missing issues, since idempotency is by title. | Add a `--dry-run` and have the script print what it creates. Add the spec-section anchor to each issue title so titles stay unique. | ☑ applied |
| 2 | minor | Project automation | GitHub Projects v2 built-in workflows are enough for 'PR merged → Done' but 'Worktree' and 'Size' fields must be set by hand or script. | Set Worktree and Size from the script (from the phase table in 1-04) so the By-worktree view works out of the box. | ☑ applied |
