# Webhook and live-status rules (checklist)

Source: spec 5 (webhooks and live status), 3-02 (tables, outcomes, transition actions), decisions D-025, D-027, D-028, D-036, D-047, D-049, D-053, D-054, D-056, D-062.
Applies to: `routes/` (webhook route), `app/Http/Middleware/VerifyPixelforgeSignature.php`, `app/Actions/Webhooks`, `app/Actions/Takes/ApplyTakeSnapshotAction.php`, `app/Jobs/ReconcileTakeJob.php`, `app/Actions/Takes/SweepStaleTakesAction.php`, `app/Events/TakeUpdated.php`, `routes/channels.php`, and the frontend live-status code.

Every rule has an ID, the violation reviewers reject, and the test that enforces it. Money rules (`money.md`) apply too: settlement inside the terminal transition (M-12), lock order (M-10), no HTTP inside a transaction (M-16), no delay above 900 s (M-17). A PR that adds a rule or a test path updates this file in the same PR.

Reviewers: a violation of any rule below is a **critical** finding unless the rule says otherwise.

## Endpoint

- [ ] **W-01 Route outside the `web` group.** `POST /webhooks/pixelforge` has no session, no CSRF, no auth guard, never redirects, and answers in under 1 s: no network call inside the request (broadcasting is queued after commit).
  Tests: `tests/Feature/Webhooks/PixelforgeWebhookTest.php` (no redirect, no session cookie; valid delivery ⇒ 200).
- [ ] **W-02 Signature on the raw body.** `VerifyPixelforgeSignature` parses `Pixelforge-Signature: t=<unix>,v1=<hex>[,v1=…]`, computes `hash_hmac('sha256', "{$t}." . $request->getContent(), secret)` and compares each non-empty `v1` with `hash_equals` (string values only); any match passes. `t` must be a positive integer, at least one non-empty `v1` must exist, and `|now − t| ≤ 300 s` (configurable).
  Reject: HMAC over `json_encode($request->all())`, `$request->json()` or any re-encoded body; `==` / `===` comparison; accepting a header without `v1`.
  Tests: `tests/Feature/Webhooks/PixelforgeSignatureTest.php` (valid; two `v1` values with the second valid ⇒ accepted).
- [ ] **W-03 Bad signature ⇒ 401, nothing written (D-056).** Invalid, tampered, stale, missing header, header without `v1`, non-numeric `t` ⇒ `401`, a warning log with the reason, and **no** `webhook_events` row, **no** `webhook_deliveries` row, no outcome. There is no `invalid_signature` outcome.
  Tests: `tests/Feature/Webhooks/PixelforgeSignatureTest.php` (each case asserts 401 and zero rows in both tables).
- [ ] **W-04 Invalid JSON with a valid signature ⇒ 400, nothing written.**
  Tests: `tests/Feature/Webhooks/PixelforgeWebhookTest.php`.

## Recording and idempotency

- [ ] **W-05 `event_id` is recorded once (D-027).** `HandlePixelforgeWebhookAction` does `insertOrIgnore` into `webhook_events` keyed by the unique `event_id` (`evt_…`). `webhook_events` holds no outcome.
  Tests: `tests/Feature/Webhooks/WebhookIdempotencyTest.php` (same `evt_` twice ⇒ one `webhook_events` row).
- [ ] **W-06 One `webhook_deliveries` row per verified delivery.** Every verified delivery appends one row (`event_id`, `webhook_event_id`, `take_id`, `type`, `attempt` from `Pixelforge-Delivery-Attempt`, `outcome`, `received_at`), including duplicates. It is the only table with the outcome, append-only, and has no business effect.
  Tests: `tests/Feature/Webhooks/WebhookIdempotencyTest.php` (two deliveries ⇒ two rows, the second `duplicate`, one settlement, 200 both times; also with the `[duplicate]` fixtures).
- [ ] **W-07 Duplicates change nothing.** An event not inserted ⇒ delivery outcome `duplicate`, `200 {"status":"duplicate"}`, no take, usage or ledger change.
  Tests: `tests/Feature/Webhooks/WebhookIdempotencyTest.php`.
- [ ] **W-08 Outcomes come from the 3-02 enums only (D-049).** Deliveries store a `WebhookOutcome` (`TransitionOutcome` + `duplicate`, `ping`, `unknown_take`, `ignored_type`). New outcome values require a 3-02 change and a decision.
  Tests: `tests/Unit/Enums/WebhookOutcomeTest.php`.

## Handling

- [ ] **W-09 One transaction, never caught inside.** The handler runs in one transaction and does not catch exceptions inside it: any exception propagates, rolls back the event row and returns 5xx, so Pixelforge's retry redelivers.
  Reject: `try/catch` that swallows inside the handler transaction; `rescue()` around settlement.
  Tests: `tests/Feature/Webhooks/WebhookFailureRollbackTest.php` (`SettleUsageAction` forced to throw ⇒ 5xx, no `webhook_events` row; the retried delivery settles once).
- [ ] **W-10 Unactionable events are acknowledged.** `ping` ⇒ outcome `ping`; any other unknown `type` ⇒ `200`, outcome `ignored_type` (no retry storm).
  Tests: `tests/Feature/Webhooks/PixelforgeWebhookTest.php`.
- [ ] **W-11 Take lookup tolerates early webhooks.** Find the take by `data.object.id` (`pixelforge_id`), else by `data.object.metadata.app_take_id`. Not found ⇒ outcome `unknown_take`, 200, warning log.
  Tests: `tests/Feature/Webhooks/PixelforgeWebhookTest.php` (`succeeded` before the create response ⇒ found by metadata; the later 202 is a no-op; unknown ⇒ `unknown_take`).
- [ ] **W-12 State changes only through `ApplyTakeSnapshotAction` (M-11).** Webhook, reconcile, the create response and admin "Reconcile now" all call the same action and follow the spec 5 outcome matrix: forward jumps allowed, backwards ⇒ `ignored_stale`, same state ⇒ `ignored_same_state`, terminal conflicts ⇒ `ignored_conflict` with a warning log **and** `takes.attention_reason`, late success after `submit_timeout`, or after `internal` on an accepted take (D-077) or on an in-doubt submit (`Take::submitWasInDoubt()`, D-082) ⇒ `orphan_success`; `last_event_at` advances only on `applied` / `ignored_same_state`.
  Tests: `tests/Feature/Webhooks/WebhookOutcomeMatrixTest.php` (table-driven from the spec 5 matrix; `succeeded` then `processing` ⇒ stays succeeded, charge unchanged).
- [ ] **W-13 Invalid snapshots change nothing.** A snapshot that names another take (`metadata.app_take_id`, or an id other than `pixelforge_id`), that nothing ties to the take, or a `succeeded` one with null `usage` / `image_url` ⇒ no state change, outcome `invalid_snapshot`, critical log, `attention_reason = invalid_snapshot`. A `succeeded` snapshot that would settle, or record an orphan's absorbed cost not yet recorded, but has no `rates` throws `SnapshotRatesMissingException` with nothing written, so the caller fetches the generation and applies that instead (spec 3-04, D-055, D-077): never settle without the generation's rates.
  Tests: `tests/Feature/Takes/ApplyTakeSnapshotActionTest.php`; `tests/Feature/Webhooks/WebhookOutcomeMatrixTest.php`.
- [ ] **W-14 Settle with the terminal snapshot's rates.** On `succeeded`, units come from `snapshot.usage` and rates from that terminal snapshot's `rates` only, never the cached `/v1/pricing` used for the estimate (D-055).
  Tests: `tests/Feature/Takes/ApplyTakeSnapshotActionTest.php` (rates overwritten at settle).

## Reconcile and sweeper

- [ ] **W-15 Reconcile is the safety net.** `ReconcileTakeJob` is `ShouldBeUnique` by take id, calls `GET /v1/generations/{id}` outside any transaction, applies the snapshot, and re-dispatches with 60, 120, 300, 600, 840 s delays (each ≤ 900 s) until terminal, for ~30 min; then `attention_reason = reconcile_exhausted`. The chain restarts when `pixelforge_id` is first set. A pending take with no `pixelforge_id` re-dispatches `SubmitTakeJob` with the same idempotency key. `404` ⇒ `reconcile_exhausted` and stop; `429` ⇒ honour `Retry-After`; `401` ⇒ fail the take free with a critical alert (D-062). A terminal take is never polled, except the one-off fetch a webhook asks for (an explicit generation id) for a failed take whose late success came without rates; a 401 or 404 on that fetch leaves the take and its failure reason as they are (no `reconcile_exhausted`).
  Tests: `tests/Feature/Webhooks/ReconcileTakeJobTest.php` (including: no re-poll of a failed take without a webhook asking; a rejected orphan fetch keeps the failure reason).
- [ ] **W-16 Opportunistic sweeper, no scheduler (D-036).** `SweepStaleTakesAction` is idempotent, throttled with `Cache::lock` (at most once per ≥ 2 min), dispatches reconcile for non-terminal takes older than 2 min (skipping only chains that stopped for a definitive reason: a 404, or a fetched generation without `rates`) and voids `pending` suggestions older than 2 min. Triggered by workspace, AI drawer and admin page loads and `takes:reconcile`. Do not add a scheduler entry.
  Tests: `tests/Feature/Webhooks/SweepStaleTakesActionTest.php` (dispatches for stale takes, respects the lock, voids stale suggestions).
- [ ] **W-17 Local development never depends on real webhooks (D-047).** Only the main checkout receives webhooks (ngrok). Pool worktrees test with signed fixtures (`tests/Fixtures/Pixelforge/*.json` signed by the test helper) and `Http::fake`; takes finish through reconcile.
  Tests: fixtures are used by every test in `tests/Feature/Webhooks/`; `Http::preventStrayRequests()` is on in `tests/Pest.php`.

## Live status

- [ ] **W-18 Broadcast after commit, never on the critical path.** `TakeUpdated` is `ShouldBroadcast`, queued, `ShouldDispatchAfterCommit`, has an explicit `broadcastAs()`, and is wrapped in `rescue()`; the Reverb client timeout is 2 s. A broadcast failure or slow broadcaster never changes the webhook status code.
  Tests: `tests/Feature/Webhooks/TakeUpdatedBroadcastTest.php`.
- [ ] **W-19 Private customer channel.** Channel `App.Models.Customer.{id}` is declared with `['guards' => ['customer']]` (D-032). Payload = `TakeLiveUpdateDTO` (take id, project id, status, failure code, updated at) plus the balance pill (`available`, `onHold`, 4-decimal and exact strings) and `balanceAt` (when it was read); money as strings, no provider cost, tokens or provider names (M-23). The card itself refreshes with a scoped partial reload (D-079).
  Tests: `tests/Feature/Auth/BroadcastChannelAuthTest.php` (own channel 200, another customer's 403, admin session 403); `tests/Feature/Customer/NoProviderLeakTest.php`; `tests/Feature/Webhooks/TakeUpdatedBroadcastTest.php` (payload shape).
- [ ] **W-20 Polling is an automatic fallback (D-028).** When the private channel is not subscribed (socket down, auth refused, server not broadcasting), a take is non-terminal and the tab is visible, the page runs `usePoll(4000, { only: ['takes', 'counts', 'auth'] })` (`auth` carries the balance pill); after the first handshake (bounded grace, ended early by a failed or lost first attempt) it shows the 2-01 Reconnecting indicator. Polling stops when the channel is subscribed again, and every successful subscription runs `router.reload({ only: ['takes', 'counts', 'auth'] })`. No env switch. Copy comes from `lang/en/*.php` (D-057). Severity: **important**.
  Tests: covered by review (`inertia-vue-reviewer`) and the `BROADCAST_CONNECTION=log` local run.
