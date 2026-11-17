# Reviews · 0-repository-and-stack

Round 1 · 2026-11-17 · reviewers: workflow agents (Claude Sonnet 5.5), product and technical lenses. Findings are inputs; resolutions are recorded in the spec and in `DECISIONS.md`.

Status legend for resolutions: ☐ open · ☑ applied · ✖ rejected (with reason).


## Product review · `0-repository-and-stack/SPEC.md` · verdict: **minor changes**

**Strengths**
- No secrets and no licensed dependencies in the public repo (D-003, D-004), so an evaluator can run it.
- One Pixelforge key per environment with its own webhook secret matches the brief's tips.
- Sail kept working as an evaluator path, with CI validating compose.yaml.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Acceptance criteria / Configuration | 'git clone + composer install + npm ci with no private credentials' is verified, but nothing says what an evaluator needs to actually generate an image locally. Without PIXELFORGE_API_KEY, the app boots but the core flow cannot run, and the evaluator must create a Pixelforge account, key and tunnel. | Add to the README 'Run locally' a 3-line prerequisites box (own Pixelforge key, tunnel optional because reconcile polling works) and make the app boot with a clear 'Pixelforge not configured' banner instead of a 500. | ☑ applied |
| 2 | minor | Stack table | Many brand-new majors at once (Laravel 13, Filament 5, Shield 4, Pest 5, Inertia v3, laravel/ai 1.x, Reverb 1.13) plus a stretch-only admin stack. Any incompatibility costs time in M0, before any product exists. | Install the Filament/echarts/activitylog packages only when M7 starts (they are stretch). Keep M0 to the starter kit, Postgres, Reverb and Pest so Gate A is reached faster. | ☑ applied |
| 3 | minor | Repository (layout, branch protection) | Branch protection with required checks and squash-only is set before the first PR; PR #1 is the harness itself. A mis-set required check can block the very first merge. | Create branch protection after the first green CI run on main, and list the exact job names it will require. | ☑ applied |


## Technical review · `0-repository-and-stack/SPEC.md` · verdict: **minor changes**

**Strengths**
- No-license, no-secret evaluator path (Sail, public composer.json) is explicit and testable.
- One API key and one webhook secret per environment matches the brief.
- Packages deliberately not used (money library, Horizon) keep the code explainable.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Environments / Queue | Local queue is the `database` driver, with no maximum delay. Laravel Cloud's managed queue caps delay at 15 min and is at-least-once. Reconcile backoff, Retry-After releases and the 6 h reconcile window will behave differently in dev and prod unless that is tested. | Add a rule and a test: no job `delay`/`release` value above 900 s, and every job is idempotent. In CI, run one test with `Queue::fake()` asserting the max backoff. Put the 15 min cap in .claude/rules. | ☑ applied |
| 2 | important | Configuration and secrets | AI_DAILY_BUDGET_USD and WELCOME_CREDIT_USD are decimal env values in USD. Casting them with `(float)` or `env(...,1.0)` would reintroduce floats in money paths. The arch test forbids float only in Billing, Takes, Suggestions and Support\Money. Config files are not covered. | Keep them as strings in config and parse only through Nanodollars::fromDecimalString. Extend the float scan to config/pixelforge.php and config/ai-pricing.php. Alternatively name them *_NANOS. | ☑ applied |
| 3 | minor | Stack | These are asserted but not shown as verified: Laravel Cloud supports PHP 8.5 and Postgres 17, the Cloud managed queue needs aws/aws-sdk-php, and Cloud Object Storage works with the S3 flysystem driver. 'Verified on Packagist' covers only package versions. | Add a pre-M9 checklist: confirm PHP 8.5, Postgres major version and queue/storage drivers in the Cloud dashboard. Pin PHP to what Cloud offers so CI matches production. | ☑ applied |
| 4 | minor | Stack / Haiku pricing | Haiku 5.5 rates ($0.10/$0.50 per 1M, in 3-03 §2) are hard-coded from a spec, and the doc does not say where they were verified. A wrong rate silently mis-charges every AI call. | Record the source and date next to config/ai-pricing.php. Add a test that the config rates equal the figures in the claude-api model table. Compute the charge from the `usage` block returned by the API, never from an estimate. | ☑ applied |
| 5 | minor | Acceptance criteria | '.env.example covers every env() call in config/' is not enforced by anything. Pixelforge limits are per account, so local runs and the deployed app share the 3-concurrent and 500/day limits if one account is used. That is an environment-level risk and is not mentioned. | Add a small Pest test that greps config/ for env() keys and compares them with .env.example. Add a line saying local dev and prod share the account-level rate and daily limits, and that demos must avoid parallel local runs while evaluators test. | ☑ applied |
