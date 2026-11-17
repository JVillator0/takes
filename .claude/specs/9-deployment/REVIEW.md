# Reviews · 9-deployment

Round 1 · 2026-11-17 · reviewers: workflow agents (Claude Sonnet 5.5), product and technical lenses. Findings are inputs; resolutions are recorded in the spec and in `DECISIONS.md`.

Status legend for resolutions: ☐ open · ☑ applied · ✖ rejected (with reason).


## Product review · `9-deployment/SPEC.md` · verdict: **minor changes**

**Strengths**
- Cost table and the $5-limit risk are stated honestly with three options; Reverb has a built-in polling fallback.
- Smoke test covers each provider keyword and leaves real data for evaluators to compare with Pixelforge.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Resources (scale to zero) | App and Postgres scale to zero. The first evaluator request (and a Pixelforge webhook arriving while asleep) pays a cold start, and the first impression of a 'most intuitive UI' is a multi-second blank page. Spend caps may also pause compute mid-review, which would be the worst possible outcome during evaluation. | During the review window turn off scale-to-zero for the app (and DB if affordable), or add a warm-up step and mention it in the README. Add to acceptance: first page load < 3 s after idle. | ✖ rejected: scale-to-zero kept (D-038); cold start measured instead |
| 2 | important | Smoke test / data left | The Pixelforge production key must report only the deployed app's generations for the admin match to prove anything. Seeded historical data (as drawn on A02/A03, 812 takes) would not exist in Pixelforge's records and would produce permanent mismatches on A04. | State that production seeding creates users and credits only, never fake takes or usage records; all takes come from real Pixelforge calls (use a separate key for staging/tests). | ☑ applied |
| 3 | minor | Open questions: Limit option A/B/C | Live status is a declared differentiator, but option B drops Reverb in production and the evaluator would only see the 4 s polling path. | Prefer option A (raise limit about $10 during review) and verify Reverb works from the public URL; fall back to B if spend exceeds the budget on day 2. | ☑ applied |
| 4 | minor | Acceptance criteria | No check that registration with welcome credit, Buy credits and a failed take display correctly on the public URL, nor the mobile layout (P10). | Add those three to the checklist. | ☑ applied |

**Questions for Javier**
- Reverb in production and the spending limit Options: A: raise the limit to about $10 and keep Reverb / B: stay at $5, polling fallback only / C: stay at $5 with Reverb and watch spend. Recommendation: A, with a day-2 spend check and a documented switch to B (BROADCAST_CONNECTION=log).
- Idle behaviour during the review window Options: Keep scale-to-zero, accept cold starts / Disable scale-to-zero on the app only / Disable on app and database for the review window. Recommendation: Disable on the app only; the database wake is usually faster than app boot.


## Technical review · `9-deployment/SPEC.md` · verdict: **minor changes**

**Strengths**
- Risks are stated openly (the $5 limit vs Reverb) with options, and the polling fallback means the required scope survives without Reverb.
- No scheduler, no polling widgets and DB-backed session/cache are consistent with scale-to-zero cost goals. Secrets come from 1Password pipes, never written to disk.
- The smoke test runs every Pixelforge keyword, then verifies against Pixelforge's own dashboard deliveries and /v1/usage, which is exactly the evidence evaluators want.

**Findings**

| # | Severity | Section | Issue | Suggestion | Resolution |
|---|---|---|---|---|---|
| 1 | important | Risk: the $5 limit | D-005's limit is settled, but it concretely defeats the required deliverable: Reverb alone is $5/mo, plus the plan fee and compute/DB/queue. The limit will pause compute during the review window, so the evaluator sees a down app, which is the worst possible outcome for the required 'Deploy' criterion. Option C accepts exactly this outcome. | Treat the limit as an input to the architecture. Either raise it for the review period (A) or drop production Reverb (B) and demo Reverb locally/in the README video. Never choose C. Add an acceptance check 'projected monthly spend < limit' from the Cloud dashboard after 24 h and set an alert. | ☑ applied |
| 2 | important | Resources: scale-to-zero compute and serverless Postgres | Cold start of both compute and DB adds seconds on the first request. Pixelforge webhooks have a 10 s deadline (retries at 10 s, 30 s, 2 m ...), and the evaluator's first page load and one-click demo login are the first impression. Delayed queue jobs also need a worker to wake. Nothing measures this. | Add to the smoke test: measure cold-start time of / and of POST /webhooks/pixelforge ping after idle (target < 10 s). During the review window keep minimum 1 replica (or accept it and document). Record numbers in the README. | ☑ applied (min replica not used, D-038) |
| 3 | important | External keys / DEMO_LOGIN_ENABLED=true | Pixelforge limits are per account across all keys (60/min, 3 concurrent, 500/day), and the same account serves local dev, CI experiments, the evaluators' sessions and the interviewer's own testing. A public one-click demo login plus fake purchases (free credit) lets any visitor or bot exhaust the daily 500, after which every later evaluator gets 'daily_limit_exceeded'. | Add app-level caps in the policy: per-customer daily takes (e.g. 40) and a global soft cap (e.g. 300/day) with a clear 'demo quota reached' reason code; throttle the demo-login route per IP. Use a different Pixelforge key per environment as the brief says, and avoid heavy local testing against the production key's day. | ☑ applied |
| 4 | important | Issue 3: seed and smoke data | Seeding demo accounts through ledger promo_grant entries is not idempotent: re-running a deploy hook or the seeder double-grants. Smoke prompts also run as the demo customer, consuming its balance and leaving takes in the evaluator's demo account (and the Low balance account must stay near $0.01). | Make the seeder idempotent (create the customer only if missing; grant only on creation, guarded by the unique rule from spec 6). Run the seed as a one-off command, not in the deploy script. Use a dedicated 'showcase' customer for the smoke data so demo accounts keep their documented balances. Add a final SQL check that no usage is open and no take is non-terminal. | ☑ applied |
| 5 | important | Configuration: queue and Reverb env | Details that commonly break on Cloud. QUEUE_CONNECTION=cloud is SQS-style (at-least-once, 15-min max delay), so the 15 m reconcile backoff sits exactly at the limit and long-running download jobs need a visibility timeout above their runtime. Reverb needs VITE_REVERB_* at build time. With BROADCAST_CONNECTION=log (option B) the client must not try to open a websocket, or the console fills with errors and the fallback logic is confused. Production needs APP_DEBUG=false, trusted proxies and secure cookies. | Use reconcile delays <= 14 min, and set job timeouts below the visibility timeout. Add a VITE_BROADCAST_ENABLED flag read by the client (spec 5). List the VITE_REVERB_* variables in the build step for options A/C. Add APP_DEBUG=false, LOG_CHANNEL=stderr, APP_URL and TRUSTED_PROXIES to the env list. The version claims (>= 13.19, cloud CLI 0.6.7, prices) should be re-checked at deploy time; keep the date stamp. | ☑ applied (no VITE flag: automatic fallback, D-028) |
| 6 | minor | Acceptance criteria | They check only that things 'serve' and that spend is under the limit. They do not require evidence for the robustness criteria that the brief weighs second. | Add: ping from the Pixelforge dashboard returns 200 with a valid signature and an invalid-signature curl returns 401; a [duplicate] [succeed] take produces exactly one ledger charge; /v1/usage match shows for every day with data; a [slow] take survives an idle period; no usage is open and no take non-terminal at the end. | ☑ applied |
| 7 | minor | Public repo hygiene | The spec includes the 1Password vault and item names, the Cloud org arrangement and CLI token handling in a repo that will be public. | Keep the operational details in a private note and leave only 'secrets come from a password manager' in the public spec. | ☑ applied |

**Questions for Javier**
- How do you resolve the $5 spending limit vs production Reverb? Options: A. Raise the limit to ~$10-15 for the review window and keep Reverb in production / B. Keep $5 and drop production Reverb (polling fallback only); show Reverb locally/in a short recording / C. Keep $5 and Reverb and accept possible pause. Recommendation: A for the review window (a few weeks), with an alert; fall back to B at once if spend approaches the limit. Never C. If Reverb live status is a headline differentiator, evaluators must see it live.
