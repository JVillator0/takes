# 5 · Webhooks and live status

Status: **Approved** · Milestone: **M5 Webhooks & live** (required) · Depends on: 3 (tables, enums, `ApplyTakeSnapshotAction`, channel auth) · Worktree: **b** · Parallel with: 4, 7
Reviews: product ☑ · technical ☑ · Wireframes: P04 (live indicator, toasts), A06, A11

## Goal
Reach the correct final state and the correct charge regardless of duplicates, ordering or lost webhooks, and show it live.

## Endpoint `POST /webhooks/pixelforge`
- Outside the `web` middleware group (no session, no CSRF), never redirects, answers in < 1 s (no network call inside the request; see Broadcasting). Local development uses plain ngrok (D-047): if the tunnel restarts, update the webhook URL of the local key in the Pixelforge dashboard; production uses the Laravel Cloud URL.
- `VerifyPixelforgeSignature` middleware:
  - Parse `Pixelforge-Signature: t=<unix>,v1=<hex>[,v1=…]`. `t` must be a positive integer and at least one non-empty `v1` must exist, else reject.
  - `expected = hash_hmac('sha256', "{$t}." . $request->getContent(), config secret)`; compare each non-empty `v1` with `hash_equals` (string values only); any match passes.
  - Reject if `|now − t| > 300 s` (configurable). Missing/invalid/stale ⇒ `401` (D-056), nothing written (no `webhook_events` or `webhook_deliveries` row, no outcome), warning log with reason. (Docs show 400; any non-2xx is retried by Pixelforge, 401 signals auth failure.)
- `HandlePixelforgeWebhookAction` (one transaction, **never catches inside it**: any exception propagates, rolls back the event row and returns 5xx so Pixelforge's retry redelivers):
  1. Invalid JSON with a valid signature ⇒ `400`, nothing written.
  2. `insertOrIgnore` into `webhook_events` keyed by `event_id` (`evt_…`). Every verified delivery also appends a `webhook_deliveries` row (D-027). Event not inserted ⇒ delivery outcome `duplicate`, return `200 {"status":"duplicate"}`, nothing else changes.
  3. `ping` ⇒ outcome `ping`. Any other unknown `type` ⇒ `200`, outcome `ignored_type` (no retry for unactionable events).
  4. Find the take by `data.object.id` (`pixelforge_id`), else by `data.object.metadata.app_take_id` (webhook can arrive before the create response). Not found ⇒ outcome `unknown_take`, 200, warning.
  5. `ApplyTakeSnapshotAction` ⇒ outcome from the matrix below.
- Broadcasting happens after commit and never inside the request's critical path (see Live status): Reverb problems never fail or delay a webhook.

### Tables (D-027, D-052)
Both tables and the outcome enums are migrated in 3-02 (Gate B); this spec adds only behavior on top of them and does not restate their columns. Column names are exactly those in 3-02 "Shared tables": `webhook_events` (`event_id` unique, `type`, `generation_id`, `payload`, `received_at`) is the "recorded once" proof and holds no outcome; `webhook_deliveries` (`event_id`, `webhook_event_id`, `take_id`, `type`, `attempt` from `Pixelforge-Delivery-Attempt`, `outcome`, `received_at`) is the only table that holds the outcome, one append-only row per verified delivery including duplicates, with no business effect. A06 (per-take deliveries, "event evt_x received 2x") and A11 (Duplicates KPI, Attempt column, outcome filter) read from `webhook_deliveries` with these names; spec 7 reads the same columns.
- Outcomes: `TransitionOutcome` and `WebhookOutcome` are defined once in 3-02 (D-049); this spec does not restate the list. The webhook handler stores a `WebhookOutcome` on the `webhook_deliveries` row (the transition outcomes plus `duplicate`, `ping`, `unknown_take`, `ignored_type`). `invalid_signature` is not an outcome: a bad signature writes nothing.

## `ApplyTakeSnapshotAction` (built in 3-02; shared by submit, webhook, reconcile and admin "Reconcile now")
This spec owns the matrix tests and the wiring; the action itself is built in 3-02.
1. `lockForUpdate` the take.
2. Fill missing facts (pixelforge_id, started_at, image fields) and set `takes.last_event_at = now()` on every snapshot that advances or confirms state, i.e. outcome `applied` or `ignored_same_state` (D-053 "stuck" is defined in spec 7; D-077). When `pixelforge_id` is set for the first time, dispatch a fresh `ReconcileTakeJob` chain (see Reconcile).
3. Validate: a snapshot for another take (`metadata.app_take_id` or generation id mismatch, or nothing ties it to the take) or a `succeeded` snapshot with null `usage` ⇒ no state change, outcome `invalid_snapshot`, critical log, `takes.attention_reason = invalid_snapshot` (D-054; shown in Needs attention, spec 7). A `succeeded` snapshot that would settle but has no `rates` throws `SnapshotRatesMissingException` before any write (D-077): the handler lets it propagate (5xx, Pixelforge retries) and the reconcile job fetches the generation and applies that snapshot; never settle without the generation's own rates (D-055).
4. Apply per the matrix. On `succeeded` ⇒ `SettleUsageAction(units from snapshot.usage, rates from snapshot.rates of the terminal snapshot only, never the cached /v1/pricing used for the estimate; completed_at)`. On `failed` ⇒ `VoidUsageAction` (releases the hold, D-024) + failure fields.
5. After commit: dispatch `TakeUpdated`; on success dispatch `PersistTakeImageJob`.

| Current take | Incoming | Result | Outcome |
|---|---|---|---|
| non-terminal | later non-terminal / terminal | advance (settle or void if terminal) | `applied` |
| non-terminal | same or earlier non-terminal | none | `ignored_same_state` / `ignored_stale` |
| `succeeded` | `succeeded` | none (may refresh `image_url` only) | `ignored_same_state` |
| `succeeded` | `processing`/`queued` | none | `ignored_stale` |
| `succeeded` | `failed` | none, warning log, `attention_reason = internal_failure` | `ignored_conflict` |
| `failed` (any cause) | `failed` | none | `ignored_same_state` |
| `failed` locally with `failure_code = submit_timeout` (ambiguous window expired, D-025/D-049), or `internal` on a take that had a `pixelforge_id` when it failed (accepted, then failed free, e.g. revoked key D-062; a failed take adopts a generation id only as an orphan; D-077) | `succeeded` | usage stays voided, cost absorbed, `attention_reason = orphan_success` | `orphan_success` |
| `failed` with any other code (`provider_busy`, `cancelled`, content/validation, `internal` before Pixelforge accepted it, or provider `failed`) | `succeeded` | none, warning log, `attention_reason = internal_failure` (`provider_busy`/`cancelled` mean nothing was created, so this is unexpected) | `ignored_conflict` |

The Pest outcome tests are driven from this table. `ignored_conflict` always writes a warning log and sets `takes.attention_reason` (the flag is a durable column, not a log line).

## Reconcile (polling safety net)
- `ReconcileTakeJob` (`ShouldBeUnique` by take id): `GET /v1/generations/{id}` ⇒ `ApplyTakeSnapshotAction`. Re-dispatches itself with delays 60 s, 2 m, 5 m, 10 m, 14 m (all ≤ 900 s, the managed-queue limit) until terminal, for at most ~30 min (Pixelforge completes in ≤ 90 s and retries webhooks over ~27 min); then sets `attention_reason = reconcile_exhausted`. The chain is restarted (fresh ~30 min) when `pixelforge_id` is first set, so a late provider accept at minute 29 still gets a full window. A pending take with `pixelforge_id` null is not polled: reconcile re-dispatches `SubmitTakeJob` (same idempotency key, D-025/D-033) as in spec 4 "Lost jobs". `404` ⇒ `attention_reason = reconcile_exhausted` and stop; `429` ⇒ honour Retry-After; `401` (revoked key) ⇒ fail the take free immediately with a critical alert (D-062).
- **Opportunistic sweeper (D-036):** idempotent `SweepStaleTakesAction` (1) dispatches `ReconcileTakeJob` for non-terminal takes older than 2 min and (2) voids `pending` AI suggestions older than 2 min (spec 8 relies on this). Triggered from workspace, AI drawer and admin dashboard loads and from the Needs attention page, throttled with `Cache::lock` (at most once per ≥ 2 min). Recovers lost chains. Also exposed as Artisan `takes:reconcile {--take=} {--stale}`. No scheduler (scale-to-zero kept, D-038).
- Admin "Reconcile now" (A06) applies the same action per take. Day-level mismatch drill-down (spec 7, D-034, D-058) lists in-flight takes, takes with outcome `unknown_take`, provider generations missing locally from `GET /v1/generations`, and a separate group "Orphan success (cost absorbed)" for `orphan_success` takes; each has Reconcile now (same action as A06).

## Live status
- Event `TakeUpdated` (`ShouldBroadcast`, queued, `ShouldDispatchAfterCommit`) with explicit `broadcastAs()` name, on private channel `App.Models.Customer.{id}`, authorised with `Broadcast::channel(..., ['guards' => ['customer']])` (D-032). Payload = `TakeLiveUpdateDTO` (take id, project id, status, failure code, updated at) plus the balance pill (`available`, `onHold`, as server strings); never provider cost, tokens or provider names (D-023, D-079). Reverb client `client_options.timeout` set to 2 s; the broadcast is wrapped in `rescue()`.
- Frontend: `useEcho` from `@laravel/echo-vue` refreshes the card with a scoped `router.reload({ only: ['takes', 'counts', 'auth'] })` (D-079; `auth` carries the balance pill) and updates the balance pill from the payload; the page is live only while the private channel is subscribed, and every successful subscription (first one, after a reconnect, or on mount when already subscribed) reloads once.
- **Automatic fallback (D-028, no env switch):** when Echo is not connected and any take is non-terminal and the tab is visible, `usePoll(4000, { only: ['takes', 'counts', 'auth'] })` runs; the header shows the Reconnecting indicator from 2-01. Polling stops when the socket reconnects. Local/test with `BROADCAST_CONNECTION=log` simply behaves as "not connected".
- Toast, `aria-live` and "Reconnecting" copy come from the single source in 2-01 (D-057, lang files); this spec restates no strings and uses no provider or cost wording.

## Tests (Pest)
- Valid signature ⇒ applied; invalid signature, tampered body, stale timestamp, missing header, header without `v1`, non-numeric `t` ⇒ 401 and no rows. Two `v1` values with the second valid ⇒ accepted.
- Unknown event type ⇒ 200 `ignored_type`; invalid JSON with valid signature ⇒ 400.
- Duplicate `evt_` ⇒ one `webhook_events` row, two `webhook_deliveries` rows (second `duplicate`), one settlement, 200 both times (also with `[duplicate]` fixtures).
- Out of order: `succeeded` then `processing` ⇒ stays succeeded, charge unchanged. Full outcome matrix table-driven.
- `succeeded` before the create response ⇒ found by metadata; later 202 is a no-op.
- `succeeded` with null usage ⇒ `invalid_snapshot`, no state change. `succeeded` without rates ⇒ `SnapshotRatesMissingException`, nothing written; the refetched snapshot settles once (D-077).
- `failed` ⇒ not charged, hold released. Late `succeeded` after a `submit_timeout` failure ⇒ `orphan_success`, usage stays voided; late `succeeded` after `provider_busy`/`cancelled` or `succeeded`-then-`failed` ⇒ `ignored_conflict` with `attention_reason` set.
- `SettleUsageAction` forced to throw ⇒ 5xx, no `webhook_events` row; the retried delivery settles once.
- Reconcile job restarts its chain when `pixelforge_id` is first set, re-dispatches `SubmitTakeJob` for a pending take without `pixelforge_id`, and sets `reconcile_exhausted` on 404/exhaustion; a 401 fails the take free.
- Sweeper voids pending suggestions older than 2 min.
- Reconcile job settles a take whose webhooks never arrived; re-dispatch stops at terminal; sweeper dispatches for stale takes and respects the lock.
- Broadcast failure or slow broadcaster does not change the webhook status code.
- Channel authorisation: a customer cannot subscribe to another customer's channel.

## Acceptance
- Delete a delivery in the Pixelforge dashboard (or drop webhooks): the take still settles via reconcile/sweeper while the workspace is open: within 60 s of acceptance for a normal take (first poll), about 180 s after acceptance for a `[slow]` one (second poll), since the 60/120/300/600/840 s delays are fixed above. Documented in the README walkthrough ("When webhooks never arrive").

## Issues
1. Route, middleware, handle action (tables come from 3-02).
2. Outcome-matrix tests for `ApplyTakeSnapshotAction` (action, settle and void are built in 3-02; this issue only tests them and fills the `ReconcileTakeJob` / `TakeUpdated` stubs in issues 3-4).
3. `ReconcileTakeJob`, sweeper action (takes and suggestions), command.
4. Broadcasting event, Echo client, polling fallback, toasts (copy from 2-01). Channel authorisation is built in 3-01; this issue only tests it.
