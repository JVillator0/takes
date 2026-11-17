# Reviews · 3-domain-foundation

Round 1 · 2026-11-17 · reviewers: workflow agents (Claude Sonnet 5.5), product and technical lenses. Findings are inputs; resolutions are recorded in the spec and in `DECISIONS.md`.

Status legend for resolutions: ☐ open · ☑ applied · ✖ rejected (with reason).


## Product review · `3-domain-foundation/03-SPEC.md` · verdict: **minor changes**

**Strengths**
- One ledger, three actions, idempotent settle/void, lock order documented: easy to defend live and to extend with a third provider.
- Worked example (400 chars, 373,875,000) and A06 money panel (59,230,000 cost, 74,037,500 charge, 95,931,250 hold) are arithmetically consistent.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | §5 customers.balance_nanos ('this is available') vs wireframes A05/P08/P09 | The spec defines balance_nanos as available (holds already debited). A05 shows BALANCE $10.9257 and ON HOLD $0.1139 as separate stats; P08 shows 'balance $10.9257 - on hold $0.1139 = available $10.8118'; the P08 activity column prints a running balance of 10.9257. So the wireframes model balance as total credits and available as derived, the opposite of the spec. The admin 'invariant: sum ledger = sum balances' widget (A10) is then checking something different on screen than in code. | Pick one vocabulary and use it everywhere: customers.balance_nanos = credits owned (sum of purchase/promo/charge/adjustment, no holds), held_nanos = open holds, available = balance - held. This also makes statements and the activity running balance natural (holds never appear). Update §5/§6 and the two actions accordingly. | ☑ applied |
| 2 | important | §4 / §8 vs wireframes P07, A09, A10, A11, P09, A03 (Anthropic amounts) | Anthropic numbers on the sheets are impossible under the spec's own bound. Max cost of one call is 500,000 nanodollars ($0.0005), yet A03 shows $0.0400 cost for 9 suggestions on Nov 17 (about $0.0044 each), P09 shows $0.0372 for 9 calls, while A11 correctly shows $0.002292 for 9 calls. A09/A10/P07 show a suggestion with tokens 1,480/212 charged $0.0003125, but 1,480x100 + 212x500 = 254,000 cost, charge 317,500 ($0.0003175). A02's donut shows Anthropic at 3.6% of provider cost; at real rates it is about 0.1% and would be invisible. | Recompute all Anthropic figures from tokens x (100/500) x 1.25 and use the same call counts everywhere. Either change tokens to give 250,000 cost or change the charge to 317,500. For the donut, state the real share (or use a bar with values) so the multi-provider story is still visible. | ☑ applied (spec; wireframes P07/A02/A03/A09/A10/A11/P09 to change) |
| 3 | important | §6 'Actual > hold' | The spec lets available go negative but there is no customer-facing state for it: no copy on P04/P08 for a negative available, no statement of what the composer does, no explanation that the next take is blocked until credits are added. | Add one copy line and one P05 state: 'Your balance is below zero by $X after the last take cost more than estimated. Buy credits to continue.' Keep the admin Needs attention row. | ☑ applied |
| 4 | minor | §7 Display (D-021) | A lifetime spend of 74,037,500 + 312,500 = 74,350,000 nanodollars shows as $0.0743 on A05/A09/P08; half-up at 4 decimals gives $0.0744. Sums of rounded parts also differ from the rounded sum (P08 splits $0.0740 + $0.0003 = $0.0743). | State the rule that totals are rounded from the exact sum, not from rounded parts, and fix the sheets (or accept and footnote 'may differ by $0.0001 from the sum of lines'). | ☑ applied |
| 5 | minor | §4 Estimates / pricing page A11 | A11 Pricing only lists input_text and output; the estimator and A06 also use input_image ($10/1M) and reasoning ($12.50/1M), so drift on those two would go unnoticed. | Show all four Pixelforge kinds plus the two Anthropic kinds on the Pricing page. | ☑ applied (spec; wireframes P07/A02/A03/A09/A10/A11/P09 to change) |


## Product review · `3-domain-foundation/01-SPEC.md` · verdict: **minor changes**

**Strengths**
- Permissions for what a role may do, state (credits) for what the account can afford, with reason codes shown to the user: clear, explainable and race-proof by re-checking under lock.
- Guard isolation and the auditor MFA exemption each have a single tested method.
- Demo accounts seeded through ledger entries, never by writing balances.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Guards and models: Fortify | Email verification is on in production. On the deployed app an evaluator registering ('Start with $1.00 free') cannot verify unless real mail is configured; Laravel Cloud has no default mailer, so they get stuck at the verification page and the first impression fails. | Do not require verification in production for this demo (keep MustVerifyEmail off or auto-verify on register), or document a working mailer. Verify the registration to first take path as an acceptance test. | ☑ applied |
| 2 | important | Demo accounts | One-click login hands every evaluator the same Demo customer ($10) and Low balance accounts. They share one Pixelforge account limit (3 concurrent, 500/day, 60 rpm), the same balance and each other's takes. Evaluators will see 'Waiting for capacity' from someone else's usage, and the $10 balance depletes. | Make 'Try a demo account' create a fresh ephemeral customer (promo grant, rate-limited) and keep the seeded accounts as a read-only showcase with sample history. If not, add an 'Reset demo account' action. | ☑ applied (ephemeral demo customer, see open question) |
| 3 | important | Demo accounts / auditor | The admin side needs credentials typed on the Filament login. The brief's evaluator wants to verify the report quickly and the auditor is read-only, so exposing it is safe. | When DEMO_LOGIN_ENABLED, show the auditor email/password (or a one-click button via a Filament render hook) on /admin/login, and print them in the README. | ☑ applied |
| 4 | minor | Roles: restricted; Policies | The 'restricted' customer message says 'Contact support' but no support channel exists; the Restrict action is custom UI without a sheet. | Use 'Your account is restricted. Email <address>' with a config value; show restricted state in the composer as a banner. | ☑ applied |
| 5 | minor | What the frontend receives | Shared prop auth.customer exposes 'available, on_hold'. Their meaning depends on the balance-model decision in 2-02. | Update after that decision; add 'total' only if the gross-balance model is kept. | ☑ applied |

**Questions for Javier**
- How should evaluators get a working customer account on the deployed app? Options: A. Self-registration with no email verification in production + ephemeral demo accounts / B. Self-registration with email verification and a real mailer / C. Seeded shared demo accounts only. Recommendation: A. It keeps the first-run path to under a minute and avoids shared-balance and shared-limit interference.


## Technical review · `3-domain-foundation/01-SPEC.md` · verdict: **major changes**

**Strengths**
- Permissions-versus-state split (D-015) with reason codes is clean and testable.
- Demo accounts are created through ledger entries, never by writing balances.
- The auditor MFA exemption is a single tested method.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | critical | Guards and models (default guard = web) | D-012 keeps `web` as the default guard, while customers authenticate on the `customer` guard. The starter kit, Fortify actions, `auth` middleware, `$request->user()`, `Auth::user()`, `Gate::inspect()`/`authorize()` in FormRequests and Inertia shared props all resolve the default guard. For a customer request, `auth` redirects to login, `$request->user()` is null, and `Gate::inspect()` evaluates policies for no user, so every policy is denied. 'Fortify guard = customer' only covers Fortify's own routes. | Choose one rule and test it. Keep web as default (D-012), but put every customer route in a group with `auth:customer` and a middleware `Auth::shouldUse('customer')`. Authorize with `Gate::forUser($customer)`, or call it after shouldUse. Add a test that logs in a customer and hits one route per feature. This is a concrete defect the decision causes, so treat it as a finding. | ☑ applied |
| 2 | important | Guards and models: password brokers | Two brokers (`users`, `customers`) share the default `password_reset_tokens` table unless configured. Tokens are keyed by email, so one person with an admin and a customer account (likely for the demo) can have each reset invalidate or hijack the other. | Create `customer_password_reset_tokens` and set `passwords.customers.table` to it. Add a test for the same email on both tables. | ☑ applied |
| 3 | important | Guards and models: broadcasting | `Broadcast::routes(['middleware' => ['web','auth:customer']])` authenticates the request, but channel authorization callbacks in routes/channels.php are checked against the channel's own guards. Without `Broadcast::channel('App.Models.Customer.{id}', fn, ['guards' => ['customer']])` the callback receives a null user or the web user, and private channel subscription fails. In Laravel 13 the setup also lives in `withBroadcasting()` in bootstrap/app.php. | Define the channel with `['guards' => ['customer']]` and add a test of `/broadcasting/auth` for a customer, for another customer (403) and for an admin session (403). | ☑ applied |
| 4 | important | Roles: MFA exemption | The spec says User::requiresMultiFactorAuthentication() 'or the panel's MFA requirement callback'. I could not confirm that either exists in Filament 5 as a per-user hook. As I recall, `->multiFactorAuthentication([...], isRequired: bool)` is panel-level. The exact API is the point of the MFA exemption, and it is stated loosely. | Read the Filament 5 MFA docs and write the exact mechanism in the spec. If there is no per-user hook, use a tiny custom middleware or provider override, or make the panel not required and enforce 'operator without MFA ⇒ redirect to MFA setup' in canAccessPanel(). Test operator, auditor and super_admin. | ☑ applied |
| 5 | important | Roles and permissions (Shield, two guards) | Shield and spatie assume one guard per panel. Shield's role resource lists roles from any guard unless scoped, and its `Gate::before` super-admin check also runs for Customer abilities. The spec says customer roles are 'not editable from Shield' but does not say how. | Scope the Shield role resource query to `guard_name = 'web'`. Add tests that a super_admin does not pass customer policies, that `Customer::can('takes.create')` uses guard `customer`, and set `$guard_name = 'customer'` explicitly on Customer. | ☑ applied |
| 6 | minor | Policies | The policy compares `available < maxCharge` without a lock (correctly marked as UX). `SuggestionPolicy` includes a global daily AI budget and a per-customer daily limit of 30 with no storage defined, so there is no way to test or enforce them. | State where the counts come from: a count query over `suggestions.created_at` for the UTC day, and a usage_records sum over provider = anthropic for the global budget. Name the limits as config values. | ☑ applied |
| 7 | minor | Demo accounts | The one-click login in production exposes seeded accounts with real ledger data on the public URL. Rate limit is mentioned. Registered demo customers share balances and can exhaust the $10 and the account-level Pixelforge quota for other evaluators. | Make the demo route create a fresh customer per click with the welcome grant instead of sharing one. Keep the three seeded accounts for the fixed states. | ☑ applied |

**Questions for Javier**
- How should customer-guard routes resolve the authenticated customer, given D-012 keeps `web` as the default guard? Options: Keep web as default; customer route group uses `auth:customer` plus a middleware calling Auth::shouldUse('customer') / Make `customer` the default guard and set Filament's panel `authGuard('web')` explicitly / Keep web as default and always pass the customer explicitly (Gate::forUser, no Auth::user()). Recommendation: First option. It respects D-012, makes the starter kit, Inertia props and FormRequest authorize() behave naturally, and one middleware plus one test covers the risk.


## Technical review · `3-domain-foundation/02-SPEC.md` · verdict: **minor changes**

**Strengths**
- State machine allows forward jumps, matching at-least-once, out-of-order delivery.
- Take ULID doubles as `metadata.app_take_id`, which handles webhooks that arrive before the create response.
- CHECK constraints and partial indexes are applied where they carry weight.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Take state machine / FailTakeAction | `failed` is terminal, and local failure goes through FailTakeAction. If a create call is ambiguous (timeout after Pixelforge accepted it) and the app gives up and fails the take locally, a later `generation.succeeded` webhook is ignored as stale. Pixelforge has billed the generation, but the customer has paid nothing and the usage was voided. That is lost money in the platform's own books and breaks 'every generation recorded exactly once'. | Only fail locally on a definitive provider rejection. For ambiguous outcomes keep the take pending with `last_submit_error`, and keep retrying the create with the same idempotency key (keys never expire). After a time limit flag Needs attention, not failed. Document this as an invariant: a local `failed` implies Pixelforge never accepted the request. | ☑ applied |
| 2 | important | Take state machine / owners of transitions | Transitions are owned by ApplyTakeSnapshotAction and FailTakeAction, which are defined in specs 5 and 4. Both parallel worktrees need them (see README finding), and the schema spec gives no signature for them. | Add both to this spec's issues with signatures (take, snapshot DTO) and tests. Include the outcome enum (applied, ignored_stale, ignored_same_state). | ☑ applied |
| 3 | minor | State machine: same-state events | The matrix has no 'same non-terminal state' transition (processing→processing from a duplicate-delivery with a new event id, or queued after a replayed create). The webhook spec classifies only terminal repeats. A succeeded→failed conflict (both terminal) is also not named. | Define the outcomes: same state ⇒ ignored_same_state; backwards ⇒ ignored_stale; terminal→different terminal ⇒ ignored_conflict plus a logged alert and a Needs attention row. Test all three. | ☑ applied |
| 4 | minor | takes table | `status` is called `enum`. A native Postgres enum makes migrations harder to reverse and extend. The CHECK 'failed ⇒ failure_code' exists but there are none for the opposite side: succeeded ⇒ completed_at and image_url not null; terminal ⇒ completed_at. | Use varchar + CHECK (status IN (...)). Add CHECKs for succeeded and failed fields. Add an index `(status, created_at)` for the stale-take sweeper, in addition to the partial non-terminal one. | ☑ applied |
| 5 | minor | customers, ledger FKs | `projects.customer_id` is cascade. If customer deletion cascades into takes, money rows (usage_records, ledger_entries) either block the delete or are orphaned depending on their FK, which is not stated. Append-only ledger plus cascade is contradictory. | Money tables use `restrict` FKs (or customers soft-delete). Say that customers are never hard-deleted. Add a migration test. | ☑ applied |
| 6 | minor | takes.idempotency_key | The unique idempotency key only helps against double submits if the browser generates it once per form instance. If the server generates it on each request, a double-click creates two takes and two holds. | The composer form mints the uuid on mount (and again after a successful submit). The submit action treats a unique violation as 'return the existing take'. Put that sentence in this spec, since the constraint lives here. | ☑ applied |
| 7 | minor | customers.balance_nanos | The column is named balance but documented as 'available' (Σ ledger including hold entries). The name will mislead in the interview and in the admin. | Rename to `available_nanos`, or see the ledger question in 3-03. | ☑ applied |


## Technical review · `3-domain-foundation/03-SPEC.md` · verdict: **minor changes**

**Strengths**
- The worked example is checked (0.000575 + 0.01530 + 0.258 + 0.1 = 0.373875) and the 115/100 text bound is right.
- Rates are exact at nine decimals: per-token rates 5000, 10000, 40000 and 12500 nanodollars are all multiples of 4, so the 25% markup is exact.
- Open/Settle/Void with no-op idempotency, unique (usage_record_id, type), sign CHECKs and an invariant test are the right shape.
- Cost uses rates snapshotted at creation, as the docs require, and billing_date is the UTC completion date.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | §4 Estimates | The docs say text tokens are `ceil(characters/4)`, and the actual count is within 15%. 'Characters' is not defined. PHP `strlen` counts bytes, `mb_strlen` counts code points, and JS `.length` counts UTF-16 units. A non-ASCII or emoji prompt can put the estimate, and the hold, outside the real range. §6 'actual > hold' then triggers for something the app caused. | Use `mb_strlen` consistently on the server, apply the 4000-char limit the same way, and add tests with accents and emoji. Decide and test which count the provider uses with a real prompt on the local key before M4. Keep the over_estimate path but treat it as a bug signal. | ☑ applied |
| 2 | important | §6 Actions / lock order | Open, Settle and Void lock customer then usage. Because every settlement already holds the customer row lock, locking the usage row adds nothing. It does add a second lock to reason about. Also nothing enforces that the caller holds the take lock: a future caller (admin tool, AI path) could call SettleUsageAction without one and invert the order. | Lock only the customer row, then re-read the usage status under that lock. Add `assert(DB::transactionLevel() > 0)` at the top of each action. Document 'take or suggestion → customer' as the only order, and add a test that runs Settle for two takes of one customer in parallel. | ☑ applied |
| 3 | important | Tests: concurrency | 'Parallel processes test' cannot run in Pest's usual transaction-wrapped DB (RefreshDatabase) because uncommitted locks are invisible to other connections, and it cannot run inside the paratest worker's own database either without care. | Specify the mechanism: `DatabaseMigrations` or a committed seed, then spawn two `Process::run` artisan commands (or `Concurrency::run`) against the same test DB, in a serial Pest group. Assert one InsufficientCreditsException and one hold row. | ☑ applied |
| 4 | important | §5 ledger_entries / holds as entries | Holds are modeled as ledger entries, so every take creates three rows (hold, hold_release, charge), balance means 'available after holds', and statements and the admin report must filter types. A hold is a reservation, not a money movement, and the ledger is meant to be the explainable source of truth. | See question below. If kept, name the cached column `available_nanos` and state that statements sum only purchase, promo_grant, charge and adjustment. | ☑ applied |
| 5 | minor | §6 Settle: cost verification | Settlement trusts units from the snapshot and computes cost itself. If the snapshot includes a cost field or `GET /v1/usage` later disagrees (the admin report must match it), nothing compares them at settle time. | If the snapshot carries `cost_usd`, compare it with the computed cost at settlement. On mismatch settle with the provider's value (it is what Pixelforge bills) and log a `cost_mismatch` Needs attention row. | ☑ applied |
| 6 | minor | §5 usage_records morph | `billable_id` is declared as a morph with ulid ids on Take but Suggestion ids may differ. A bigint morph column will fail on ulids. A single unique pair is correct. | Use `ulidMorphs`/a string column for both and make both tables use ulids. Keep the morph map in AppServiceProvider to avoid class names in the DB. | ☑ applied |
| 7 | minor | §2 Anthropic kinds | `cache_read` and `cache_write` 'if ever used' is speculative surface for a single-shot call. Rates in config/ai-pricing.php must be strings, not floats ('0.10'). | Drop cache kinds until used. Parse config rates through perMillionFromDecimalString, and include the config file in the float scan. | ☑ applied |
| 8 | minor | §6 Actual > hold | The docs bound text tokens to ±15% and output tokens are exact, so the hold covers the true maximum. The branch where actual exceeds hold is reachable only by estimator bugs or stale cached pricing (getPricing is cached 1 h). | Keep it as a defensive branch, but compute the hold from the pricing used for the create call, store that rate snapshot on the usage at open, and keep the test small. | ☑ applied |

**Questions for Javier**
- Should holds be ledger entries, or state on the usage record with available = balance − held? Options: Keep hold and hold_release as ledger entries (current) / Holds live only on usage_records.max_charge; the ledger holds real money movements (purchase, promo_grant, charge, adjustment) and available = balance − Σ open holds / Keep ledger entries for holds but exclude them from statements via a flag. Recommendation: Second option. It halves ledger rows per take, removes two entry types and their sign CHECKs, makes statements and the admin report trivial, and is easier to explain in the interview. The exactly-once guarantee still comes from the customer row lock and the usage status.


## Technical review · `3-domain-foundation/04-SPEC.md` · verdict: **minor changes**

**Strengths**
- The client is thin, with no retries inside; the jobs own retry policy.
- The exception mapping covers the documented error codes, including Retry-After.
- Idempotent-Replayed is exposed so callers can tell a replay from a new generation.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Errors → exceptions | 401, 404, 409 and 422 all become PixelforgeRejected and 'fail take free'. A 401 (revoked or missing key) is a configuration outage, not a bad take: it would fail and void every customer's takes. 409 idempotency_key_reused would mean a retry changed its parameters. In both cases the take may already exist at the provider (see 3-02 finding). | Split 401 into PixelforgeMisconfigured: keep takes pending, alert, do not fail. Treat 409 as a bug: keep the take pending and flag Needs attention. Only 400 content_policy_violation and 422 validation_failed fail the take, because the provider provably did not create it. | ✖ rejected: D-025 (401/409 fail free; critical alert added) |
| 2 | important | Errors → exceptions: concurrency | Pixelforge allows only 3 in-flight generations and 60 requests/min per account. With many customers the normal state is `concurrency_limit_exceeded`. Each submit job would hit the API, get rejected and be released with Retry-After, creating herds of retries and wasting the per-minute budget. | Add a global gate in the submit job (for example `Cache::lock` or a counter of non-terminal pixelforge_id takes, with 3 as the cap, and a Redis-less variant using the database). Keep PixelforgeBusy as the fallback. Parse Retry-After as seconds and as HTTP-date, add jitter, and cap at 900 s for the managed queue. | ☑ applied |
| 3 | minor | Errors → exceptions: daily_limit_exceeded | `daily_limit_exceeded` (500 per UTC day, account-wide) is treated as a permanent rejection. It resets at UTC midnight and is shared by every user and by dev runs. | Either fail free with a clear copy ('Daily capacity reached, try again after 00:00 UTC') as written, or keep pending until the reset. State the product choice in spec 4. Do not retry blindly. | ☑ applied |
| 4 | minor | Shape: HTTP | Timeout 10 s for POST /generations. A timeout may hide a 202 that succeeded server-side. The retry relies on the same Idempotency-Key, which is right, but the spec does not say the key is persisted before the call. | State that `takes.idempotency_key` is saved before the first request and that the client sends it unchanged on every attempt. Add a test for replayed=true after a simulated timeout. | ☑ applied |
| 5 | minor | getPricing / DTOs | Cached 1 h with a config fallback: estimate and settlement can disagree if the rate changes, and the settlement must use the rates carried by the generation. The DTO list says `rates` on GenerationSnapshotDTO but the docs excerpt says rates are 'kept per generation' without naming the field. | Confirm the exact field in the real response and the webhook payload (the brief does not include them). Keep a fixture from a real response in tests. If rates are missing from the webhook payload, fetch the generation before settling. | ☑ applied |
| 6 | minor | Usage endpoint | `getUsage` is described, but its role is in the admin spec: provider cost per day must equal the sum of settled usage_records by billing_date, for pixelforge only and for the current key only. | Add the one-line contract here (days are UTC, key-scoped, 92-day maximum, so chunk longer ranges) so spec 7 can rely on it. | ☑ applied |

**Questions for Javier**
- When Pixelforge cannot be reached or returns an ambiguous result and retries are exhausted, what happens to the take and its hold? Options: Fail the take locally and void the hold (current behavior) / Keep it pending, keep retrying the create with the same idempotency key, and flag Needs attention after a time limit; never mark failed / Fail after N attempts but keep the usage open so a late succeeded webhook can still settle. Recommendation: Second option. Keys never expire, so a retry is always safe, and it can never produce a charge-free success at Pixelforge. Show the customer 'Still trying' with a cancel button that is allowed only before pixelforge_id exists and a safe check.
