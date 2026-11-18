# 4 · Takes and generation

Status: **Approved** · Milestone: **M4 Takes** (required) · Depends on: 3 (3-01 policies, 3-02 tables/enums/`ApplyTakeSnapshotAction`, 3-03 money and `purchases`) · Worktree: **a** · Parallel with: 5, 7
Reviews: product ☑ · technical ☑ · Wireframes: P01, P03, P04, P05, P06, P10

## Goal
Customers register (with a welcome credit), create projects, compose a take with a live estimate, submit it robustly against rate limits and overloads, and see it in a grid; any take can be opened and remixed. A new customer can generate on the first deploy without any seeded account (D-019, D-026, D-030).

## Decisions applied
D-019 welcome credit · D-020 persisted images · D-021 display rounding · D-023 no provider data to customers · D-024 holds are state · D-025 ambiguous retry 30 min + `orphan_success` · D-026 minimal Buy credits here · D-030 registration without email verification · D-032 `auth:customer` · D-033 client idempotency key · D-036 opportunistic sweeper · D-042 exact amounts on money pages · D-044 cancel/try again/compare/scenario chips · D-049 enums · D-050 denial reasons and caps · D-051 purchases naming · D-052 tables migrated in M3 · D-053 stuck rule · D-057 copy single source.

## Routes (customer guard)
All customer routes use `auth:customer` + middleware `Auth::shouldUse('customer')` (D-032); at least one feature test per route group hits it as a customer.

| Method | Path | Controller → Action |
|---|---|---|
| GET | `/` | landing (Inertia `Welcome`) |
| POST | `/register` | starter-kit registration, no email verification (D-030); `GrantWelcomeCreditAction` (owned here, called from the Fortify registration hook; 3-01 only creates the customer/guard) adds a **`promo_grant`** of `config('takes.welcome_credit_nanos')` (env `WELCOME_CREDIT_USD`, default 1.00, parsed in 3-01's `config/takes.php`), in the same transaction as the customer insert. Idempotent via the unique rule in 3-03 |
| GET | `/projects` | `ListProjectsQuery` |
| POST | `/projects` | `CreateProjectAction` |
| GET | `/projects/{project}` | `GetProjectWorkspaceQuery` (takes paginated 24, status counts, `balance`/`held`/`available`) |
| PATCH/DELETE | `/projects/{project}` | rename / archive |
| GET | `/estimates/take` | `GetTakeEstimateQuery` → JSON |
| POST | `/projects/{project}/takes` | `CreateTakeAction` |
| GET | `/projects/{project}?take={id}` | the take drawer (2-01/2-02): partial reload `only: ['take']`; `TakeResource` exposes estimate min/max (exact, D-042), charged amount (exact) and a short-lived `image_url` only, never provider cost, tokens, rates or provider names (D-023). Unknown id and another customer's take return the identical 404 |
| GET | `/takes/{take}` | same `TakeResource` as JSON for non-Inertia callers; **404 (not 403)** for non-owners |
| GET | `/takes/{take}/image` | fallback: streams `image_path` (owner only, 404 otherwise), used when a temporary URL is unavailable |
| POST | `/takes/{take}/cancel` | `CancelTakeAction` (below) |
| POST | `/billing/purchases` | `PurchaseCreditsAction` (built in 3-03, see its issue 'PurchaseCreditsAction + route + purchase ledger entry (no invoice)'; this spec only consumes it) |
| POST | `/demo` | ephemeral demo customer ("Try a demo account", D-045): owned here, see Demo account below |

### Demo account (D-045)
**Single owner of the button, `POST /demo` and the per-IP throttle** (3-01 only provides the P02 panel shell and the config keys; 3-02 the columns). The landing/login "Try a demo account" button posts to `POST /demo`, which creates an ephemeral customer (`is_demo = true`, `demo_expires_at = now + takes.demo.ttl_hours`, default 24 h), grants the welcome credit through `GrantWelcomeCreditAction`, logs in on the `customer` guard and redirects to a new project. **Expiry (D-045 defines none, D-036 forbids a scheduler):** customers are never hard-deleted (money rows), so expiry is lazy: the `customer`-guard middleware rejects and logs out a demo customer whose `demo_expires_at` has passed, and the demo account cannot be used again. The throttle is middleware `throttle:demo` limited by `takes.demo.throttle_per_ip_per_hour` (3-01); over the limit it returns 429 with code `rate_limited`; spec 9 only sets the env value. Button copy and the `demo_quota_reached`/`rate_limited` messages live in 2-01. Depends on 3-01 for the guard and config and on 3-02 for the `is_demo` and `demo_expires_at` columns. Wireframe P02 is built here (the panel shell is 3-01).

### Minimal Buy credits (D-026)
A modal (P05-A CTA target) with the fixed packs from `config('takes.purchase_packs_usd')` ($5, $10, $25; defined in 3-03), no card form: confirming calls `PurchaseCreditsAction` (built in 3-03, route `POST /billing/purchases`), which writes one `purchases` row and one `purchase` ledger entry (idempotent per client uuid, `unique(customer_id, idempotency_key)`) and returns the new balance. The `purchases` table is migrated in 3-03 (D-051/D-052); this spec creates no migration for it. Invoices, the billing page and purchase history stay in spec 6, which adds the invoice row to the same action. Authorization `credits.purchase`.

## Estimate
`GET /estimates/take?chars=&size=&quality=&references=` (the prompt text is never sent for estimating). Returns `{min, max, min_display, max_display}` as strings (D-021 rounding). Uses `/v1/pricing` (cached 1 h) with config fallback. Debounced 250 ms client-side. The estimate shows the reference-image count (each reference adds input tokens).

Rules (one implementation in `TakeEstimator`, spec 3-03):
- `chars` is the Unicode character count: client `[...prompt].length`, server `mb_strlen($prompt, 'UTF-8')`. Never `string.length`.
- `est_text_tokens = intdiv(chars + cpt - 1, cpt)` (integer ceil-div, `cpt` = `characters_per_text_token`). `text_min = intdiv(est * 85, 100)` (floor), `text_max = intdiv(est * 115 + 99, 100)` (ceil). The variance and `characters_per_text_token` from `/v1/pricing` are parsed to integers (bps / int) at the DTO boundary through string casts; no float arithmetic anywhere.
- Fixtures: the worked example (373,875,000) and sheet A06 (16 chars → min 54,215,000 / max 76,745,000 nanos) are test oracles.
- On submit, `CreateTakeAction` recomputes the estimate from the real prompt on the server and uses that for the hold; the client estimate is display only.

## Create take (`CreateTakeAction`)
Input `CreateTakeDTO` from `StoreTakeRequest`: prompt 1–4000, size, quality, reference_images ≤ 4 http(s) ≤ 2048 chars, `idempotency_key` (uuid), optional `parent_take_id` owned by the customer. Throttle 10/min/customer. Authorization `TakePolicy::create` (spec 3-01). Cap config is not owned here: `config/takes.php` (all keys, defaults and env names, including `takes.daily_cap_per_customer` default 40, `takes.daily_cap_global` default 300, `takes.support_email`, `takes.ai.*`) is created in 3-01; this spec only reads it, and spec 9 only sets env values. The caps keep one account from draining Pixelforge's shared 500/day and are enforced in `TakePolicy::create` (3-01): per-customer over the cap ⇒ `daily_take_cap`; global over the cap ⇒ `demo_quota_reached` (today's shared capacity used up, any customer; 3-01). Reasons come from `App\Enums\Auth\DenialReason` (D-050).

**Idempotency (D-033).** The composer generates a uuid when it opens and again after each successful create; it is sent as a hidden field. `takes` is unique on `(customer_id, idempotency_key)`; the same value is Pixelforge's `Idempotency-Key`. In one transaction:
1. Lock the customer row; look up by `(customer_id, key)`. On a hit return the existing take: no new hold, no new job.
2. Recompute the estimate from the real prompt.
3. Insert take `pending` with `source` (`composer` or `remix`) and lineage.
4. `OpenUsageAction` (hold = max charge, state per D-024) ⇒ `InsufficientCreditsException`.
5. After commit: dispatch `SubmitTakeJob` **and** `ReconcileTakeJob` delayed 60 s (spec 5), then broadcast `TakeUpdated`. `ApplyTakeSnapshotAction` is owned by 3-02; this spec only calls it. Dispatching reconcile here, not on the 202 path, means a lost submit job still gets found.

**Denials render as Inertia validation-style errors (302 back, 422 for XHR) with `{code, message}`**, code from `DenialReason` (message text for each code lives in 2-01, D-057): `insufficient_credits`, `account_restricted`, `daily_take_cap`, `demo_quota_reached`, `rate_limited`. 403 is only for ownership failures on create (project, `parent_take_id`); take detail/image/cancel return 404 for non-owners. Tests assert these codes.

**Callable from an outer transaction.** `CreateTakeAction` may be invoked inside another action's transaction (spec 8 apply); it only asserts `transactionLevel > 0` (3-03), and the caller must pass a deterministic `idempotency_key` (e.g. uuid v5 of the suggestion id) so a double apply replays instead of creating a second take.

Response: redirect back to the workspace with the new take on top.

### Remix and reference images
Same action with `parent_take_id`; the composer is pre-filled with the parent's prompt/size/quality and the parent's image added as a reference. Source order for the reference URL: (1) persisted image as `Storage::temporaryUrl` (TTL 60 min); (2) unexpired provider `image_url` (completed less than 24 h ago); (3) neither ⇒ "Use image as reference" is disabled with the 'image no longer available' reason from 2-01. The reference counts in the estimate.

## Submit (`SubmitTakeJob` → `SubmitTakeToPixelforgeAction`)
Invariants:
- Idempotent job: skip unless status is `pending`.
- The 30-min window from `take.created_at` (D-025) ends the take: `handle()` fails it by its last outcome on the run just past the window, and `retryUntil = window end + 900 s + 60 s` so the worker never gives up first (#149); **no `$tries` cap** (release-based retries count as attempts). Backoff has jitter: `release(base + random(0..5))`. Every release delay is capped at **900 s** (Cloud limit; 3-04), including a large `Retry-After`. A job on a non-`pending` take (e.g. cancelled) is a no-op.
- Never open a DB transaction or hold a take lock across `createGeneration`; lock only to apply the snapshot/failure.
- Before calling, ask the **3-04 submit gate helper** (global in-flight cap of 3, which counts non-terminal takes with a `pixelforge_id` plus those currently submitting; this spec keeps no count of its own), or if the `RateLimited` job middleware (database cache, ~50 req/min app-wide, below Pixelforge's 60) is exhausted, skip the call and `release(5 + jitter)` without touching `submit_attempts`: the limits are per account across all keys and shared with every evaluator.
- `createGeneration` carries the take's idempotency key and metadata `{app_take_id, app_customer_id}`; increments `submit_attempts`.

| Outcome (spec 3-04 exception) | Behavior |
|---|---|
| 202 (incl. replay) | `ApplyTakeSnapshotAction` (3-02; safe if a webhook already moved it) |
| `PixelforgeBusy` (429 rate/concurrency, 503) | definite non-creation: `last_submit_error_kind = capacity` (unless already `ambiguous`); `release(min(max(Retry-After, backoff) + jitter, 900))`, where backoff grows with the take's API attempts (20, 40, 60 … capped at 120 s) so one waiting take makes at most two calls in any minute against the shared 60 rpm (#149); gate and budget waits are not attempts |
| Timeout, connection error, 5xx (ambiguous) | the provider may have created it: keep retrying the **same key** (replay is safe), `release(5 + jitter)`, `last_submit_error_kind = ambiguous` (sticky: never reverts to `capacity`) |
| 429 `daily_limit_exceeded` | `FailTakeAction('daily_limit')`, free |
| 400 `content_policy_violation` | `FailTakeAction('content_policy')` ⇒ `VoidUsageAction` |
| 422 | `FailTakeAction('validation')` ⇒ void (2-01 has the `validation` row and says whether Try again is allowed) |
| 401, 409, 404 | deployment/bug (D-062): fail immediately and free with `failure_code = internal`; critical log + Needs attention `internal_failure` (D-054) |
| `retryUntil` exceeded (30 min) | **by the last outcome**: last was `ambiguous` (any submit in doubt) ⇒ `FailTakeAction('submit_timeout')`, void; a later Pixelforge success is webhook outcome **`orphan_success`** (spec 5): usage stays voided, cost absorbed, Needs attention. Only `capacity` outcomes ⇒ `FailTakeAction('provider_busy')`, void: non-creation is definite, so a later success is impossible and is treated as `ignored_conflict` (spec 5). |

Failure copy per `failure_code` (including `submit_timeout`, `provider_busy`, `cancelled`, `internal`) comes from the 2-01 failure table (D-057); this spec never restates strings.

### Cancel (D-044)
`POST /takes/{take}/cancel` → `CancelTakeAction`: owner only (404 otherwise); valid only for a `pending` take with `last_submit_error_kind = capacity` (nothing created at Pixelforge, no submit in doubt); otherwise 422 `cannot_cancel`. Locks the take, calls `FailTakeAction('cancelled')` (allowed in 3-02), which voids the hold, then broadcasts `TakeUpdated`. A racing `SubmitTakeJob` sees a non-pending take and no-ops.

### Try again and Compare (D-044)
*Try again* (failed takes, hidden for `content_policy` and `internal`) is a client action: opens the composer with the same inputs and posts a new create with a **new** idempotency key. *Compare with parent* is frontend only (drawer shows parent and child side by side when `parent_take_id` is set and both images are available).

**Lost jobs.** `ReconcileTakeJob` (dispatched at create) on a `pending` take with no `pixelforge_id` re-dispatches `SubmitTakeJob` (same key, safe); spec 5 aligns on this. The opportunistic sweeper (D-036, spec 5) does the same for pending takes older than 2 min with no job. After a 202 the reconcile chain restarts when `pixelforge_id` is first set (spec 5).

### Thresholds (single source)
| Rule | Value |
|---|---|
| Submit retry window (all retryable outcomes) | 30 min from `takes.created_at` (the only anchor), then fail free (`submit_timeout` or `provider_busy`) |
| Max job release/backoff delay | 900 s |
| Take shows "Waiting for capacity" (with Cancel) | `pending` with `last_submit_error_kind = capacity` (0–30 min) |
| Take shows "Submitting" + "Taking longer than usual" (no Cancel) | `pending` with `last_submit_error_kind = ambiguous` |
| Pending with no job re-dispatched by reconcile/sweeper | > 2 min |
| Admin "Stuck" | defined once in spec 7 'Definitions' (D-053: `queued`/`processing` with no provider update > 15 min via `takes.last_event_at`, or `pending` > 35 min); not restated here |

## Status vocabulary
In progress = `pending` + `queued` + `processing`; the header "on hold" is the sum of the open holds of those takes and links to them.

Labels, colours, actions and money lines per state come only from the 2-01 'Take state table' (Cancel only for `pending` + `capacity`); this spec keeps no copy of that table.

Copy (labels, descriptions, failure reasons, empty states, toasts) has one source: the 2-01 state/failure tables → `lang/en/takes.php` (D-057). This spec lists no strings; change wording in 2-01 only.

## Persist image (`PersistTakeImageJob`, D-020)
On success: stream the PNG (timeout 20 s, max 20 MB enforced while streaming, content-type `image/png`, PNG magic bytes verified) to `takes/{customer}/{take}.png` on the **default disk** (`FILESYSTEM_DISK`: private local disk locally, Cloud Object Storage in production; never `public`); set `image_path`. SSRF guard: only download when the URL host equals the configured Pixelforge asset host. `backoff [30, 300, 900]` (never above 900 s), `retryUntil = completed_at + 23 h` (provider URL lives 24 h). The spec 0 Pest max-backoff test must cover this job. Failure is non-fatal: the UI falls back to `image_url` until it expires.

`TakeResource` returns `Storage::temporaryUrl(image_path, 15 min)` as `image_url` so the grid loads images straight from object storage; the owner-only image route is the fallback.

## Frontend
- Pages: `Projects/Index.vue`, `Projects/Show.vue` (workspace), the drawer opens from `?take={id}`; components `TakeComposer.vue`, `TakeCard.vue`, `TakeDrawer.vue`, `EstimatePanel.vue`, `StatusBadge.vue`, `MoneyText.vue`, `BuyCreditsModal.vue`, `ScenarioChips.vue`.
- Inertia `useForm` for create; partial reloads `only: ['takes', 'counts']`; drawer data via `only: ['take']`. Live updates come from spec 5 (Reverb + automatic polling fallback, D-028).
- **Scenario chips:** a "Try a scenario" row (visible to every customer, D-044) under the prompt appends the brief's keywords ([fail], [slow], [duplicate], [blocked], exactly the D-044 list; `[overloaded]` is typed by hand, there is no chip).
- **Empty / error states:** copy from 2-01 (empty grid, no filter results, `insufficient_credits` with "Buy credits" opening `BuyCreditsModal`, other denials show the `{code, message}` error with retry).
- **Money display (D-042):** cards, lists and the balance pill show rounded 4 decimals; the drawer shows estimate min/max and the charged amount exact (trailing zeros trimmed, never fewer than 2 decimals, per 2-01 and 3-03 §7).
- Types in `resources/js/types/take.ts`; routes via Wayfinder.

## Tests
- Estimate endpoint matches the estimator (worked example, A06 fixture, emoji/CJK character counts) and never receives the prompt text; submit recomputes from the real prompt.
- Register grants exactly one `promo_grant` of `WELCOME_CREDIT_USD`; Buy credits (`POST /billing/purchases`) writes one `purchase`, idempotent.
- Create: holds max; two identical POSTs (same key) ⇒ one take, one hold, one provider call; same key from another customer is independent; insufficient credits ⇒ `insufficient_credits` error, no take, no ledger/usage rows; restricted ⇒ `account_restricted`; per-customer cap ⇒ `daily_take_cap`; global cap ⇒ `demo_quota_reached`; ownership failure on create ⇒ 403; detail/image/cancel of another customer's or unknown take ⇒ identical 404; create from an outer transaction with a deterministic key replays.
- Submit: 202, replay, 429 rate/concurrency (released with Retry-After + jitter, same key), 503, timeout then replay succeeds, ambiguous retries exhausted at 30 min (`submit_timeout`, voided, later success ⇒ `orphan_success`), busy-only exhaustion (`provider_busy`, later success ⇒ `ignored_conflict`), release delays capped at 900 s, `last_submit_error_kind` sticky ambiguous, job no-op on non-pending, content policy (failed, voided, not charged), daily limit, 401/409 ⇒ `internal` + critical log, skipped call when the 3-04 gate reports 3 in flight, no transaction open during the HTTP call.
- Cancel: allowed only in `capacity` state, voids the hold, `cancelled`; rejected when ambiguous/queued; racing submit job no-ops; non-owner 404. Try again uses a new key.
- Pending take with no queued job is resolved by reconcile/sweeper.
- Remix lineage, ownership, reference source order (persisted → provider URL → disabled).
- Image persistence success, failure, wrong host, non-PNG, oversize.
- Customer-guard route test per route group (D-032).

## Issues
1. Registration hook + `GrantWelcomeCreditAction` + minimal Buy credits modal (consumes `PurchaseCreditsAction` and `POST /billing/purchases` from the 3-03 issue; does not build them) + "Try a demo account" button, `POST /demo` and its per-IP throttle (test: demo customer gets the welcome credit; throttle returns 429 with code `rate_limited`; demo customer past `demo_expires_at` is logged out).
2. Projects CRUD + index page.
3. Estimate query + endpoint + `EstimatePanel`, consuming `TakeEstimator` and its fixtures from 3-03 issue 4.
4. `CreateTakeAction` + request + idempotency + policy wiring (caps, `DenialReason`) + composer (+ scenario chips).
5. `SubmitTakeJob` with the outcome table, the 3-04 gate helper, rate-limit middleware and invariants; `CancelTakeAction` + route + tests.
6. Workspace grid + cards + status vocabulary + empty states + filters + drawer (`?take=`, Try again, Compare with parent, Cancel button) + remix.
7. `PersistTakeImageJob` + temporary URLs + image route.
8. Landing page, plus the single owner of the *service not configured* banner UI (2-01 copy, no provider name, D-023) and the composer's submit disabling, driven by the 3-04 prop `imageService.configured` (copy in 2-01; test: banner visible and submit disabled with the key unset, hidden when set).
