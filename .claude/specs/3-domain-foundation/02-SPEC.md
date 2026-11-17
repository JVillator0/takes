# 3-02 · Schema and take state machine

Status: **Approved** · Milestone: **M3 Domain foundation** · Depends on: 3-01 · Unblocks: 4, 5, 6, 7, 8 · Parallel: no (lead)
Reviews: product ☑ · technical ☑ · Decisions: D-024, D-025, D-027, D-033, D-049, D-052, D-053, D-054

## Tables (all migrations reversible; timestamps are `timestamptz`, UTC)
Enum-like columns are `varchar` + `CHECK (col IN (...))`, never native Postgres enums (easier to reverse and extend); the PHP backed enum is the source of the list.

### `customers`
`id` bigint · `name` · `email` unique · `email_verified_at` (unused, D-030) · `password` · `remember_token` · `is_demo` boolean default false · `demo_expires_at` timestamptz null (ephemeral demo accounts, spec 4) · `balance_nanos` bigint default 0 (cached Σ ledger = credits owned, **no holds**, D-024) · `held_nanos` bigint default 0 (cached Σ open usage holds) · `last_active_at` · timestamps.
**available = balance − held** (model accessor `available()`; may be negative, spec 3-03 §6). CHECK `held_nanos >= 0`. Customers are **never hard-deleted** (no cascade into money rows).

### `users` (admins, Laravel default). Filament MFA columns are added by spec 7 (D-048).

### spatie permission tables (default), used by both guards. Password reset tokens: `password_reset_tokens` (users) and `customer_password_reset_tokens` (customers), spec 3-01.

### `projects`
`id` ulid · `customer_id` FK **restrict** · `name` (≤ 80) · `archived_at` null · timestamps. Index `(customer_id, updated_at desc)`.

### `takes`
| column | type | notes |
|---|---|---|
| id | ulid PK | also `metadata.app_take_id` at Pixelforge |
| customer_id | FK restrict | index `(customer_id, created_at desc)` |
| project_id | FK restrict | index `(project_id, created_at desc)` |
| parent_take_id | FK takes null | remix / AI lineage |
| suggestion_id | FK suggestions null | set when created from an AI proposal |
| source | varchar `composer`, `remix`, `ai_change` | |
| idempotency_key | uuid | **unique `(customer_id, idempotency_key)`** (D-033) |
| pixelforge_id | string unique null | `gen_…` from create response or first webhook |
| status | varchar (below) | partial index where non-terminal; index `(status, created_at)` for the sweeper |
| prompt | text (≤ 4000 characters, `mb_strlen`) | |
| size, quality | varchar | |
| reference_images | jsonb array | ≤ 4 URLs |
| failure_code, failure_message | string null | `failure_code` is one of: `generation_failed`, `internal_error` (provider outcomes); `content_policy`, `daily_limit`, `validation`, `internal` (definite rejections); `provider_busy` (30 min of 429/503: definite non-creation); `submit_timeout` (30 min ambiguous: orphan possible); `cancelled` (D-049, D-044) |
| submit_attempts | smallint default 0 | the 30-min retry window (D-025) is measured from `created_at`; there is no separate anchor column |
| last_submit_error | string null | internal reason while pending (copy comes from 2-01, D-057) |
| last_submit_error_kind | varchar null `capacity`, `ambiguous` | `capacity` ⇒ "Waiting for capacity" with Cancel; `ambiguous` ⇒ "Submitting / taking longer than usual", no Cancel (2-01) |
| last_event_at | timestamptz null | last provider update (webhook, reconcile, create response); basis of the *stuck* rule, defined once in spec 7 (D-053) |
| attention_reason | varchar null | durable *Needs attention* flag (D-054): `invalid_snapshot`, `orphan_success`, `cost_mismatch`, `reconcile_exhausted`, `internal_failure`, `over_estimate`; the view itself is derived (spec 7) |
| image_url, image_url_expires_at | text, timestamptz null | provider URL |
| image_path | string null | persisted copy (D-020) |
| submitted_at, started_at, completed_at | timestamptz null | `completed_at` = provider value for provider outcomes, local time for local failures |
| timestamps | | |

CHECKs: `status = failed ⇒ failure_code not null`; `status = succeeded ⇒ completed_at not null and image_url not null`; `terminal ⇒ completed_at not null`.

**Idempotency (D-033).** The composer mints the uuid per draft on mount (and again after a successful submit). The submit action treats a unique violation on `(customer_id, idempotency_key)` as "return the existing take": no new take, no new hold. The same key is sent as Pixelforge's `Idempotency-Key` on every attempt and is persisted before the first request.

Money for a take lives in its **usage record** (spec 3-03), not in `takes`.

### Shared tables, all migrated here under Gate B (D-052)
Specs 5, 7, 8 add behavior only, never migrations for these.
- `usage_records`, `ledger_entries`, `purchases`: columns defined in spec 3-03 §5. (`invoices` and `invoice_counters` are spec 6; there is no `invoice_lines`, D-051.)
- `suggestions` (ulid), **the single column list** (spec 8 adds behavior only): `id`, `customer_id` FK restrict, `project_id` FK restrict, `base_take_id` FK takes (the take the instruction applies to; `takes.parent_take_id` is the lineage of the new take), `instruction` text, `request_key` uuid (client-minted, **unique `(customer_id, request_key)`**, replay returns the existing suggestion), `proposal` jsonb null, `rationale` text null, `model` varchar null, `status` (`SuggestionStatus`: `pending`, `proposed`, `applied`, `discarded`, `failed`, `CHECK` on exactly these; `timeout` is a `failure_reason`), `failure_reason` varchar null, `resulting_take_id` FK takes null (take created from the proposal), `expires_at` timestamptz, timestamps; index `(customer_id, created_at)`. Created before `takes` so `takes.suggestion_id` has its FK, plus the **partial unique index on `takes(suggestion_id) where suggestion_id is not null`**.
- `webhook_events` (one row per distinct event, **no outcome**): `id`, `event_id` **unique**, `type`, `generation_id` null, `payload` jsonb, `received_at`.
- `webhook_deliveries` (append-only, one row per verified delivery, D-027; **the only table that holds the outcome**): `id`, `event_id`, `webhook_event_id` null FK, `take_id` null FK, `type`, `attempt`, `outcome` (`WebhookOutcome`), `received_at`.
These are the final column names; specs 5 and 7 and wireframe A06 use exactly them (`generation_id`, `attempt`, `outcome` on deliveries). A request with a bad signature writes nothing (401, D-056).

## Take state machine (`App\Enums\Takes\TakeStatus`)
```
pending    → queued | processing | succeeded | failed
queued     → processing | succeeded | failed
processing → succeeded | failed
succeeded  → (terminal)
failed     → (terminal)
```
- `pending`: row exists locally, not yet accepted by Pixelforge (submitting, waiting for capacity, or retrying an ambiguous outcome).
- Forward jumps are allowed (a `succeeded` webhook may arrive before `processing` or before the create response).
- Methods: `transitions()`, `canBecome(self)`, `isTerminal()`, `label()`, `color()` (+ Filament `HasLabel`, `HasColor`).
- **Outcomes**, written once here (D-049; spec 5 references this list). `App\Enums\Takes\TransitionOutcome`: `applied`; `ignored_same_state` (same status from a new event id); `ignored_stale` (backwards, e.g. processing after succeeded); `ignored_conflict` (terminal → a different terminal, or a late success after a definite local failure; warning log + `attention_reason`); `orphan_success` (see invariant); `invalid_snapshot` (payload fails validation; `attention_reason`, no state change). `WebhookOutcome` = `TransitionOutcome` + `duplicate`, `ping`, `unknown_take`, `ignored_type`. There is no `invalid_signature` outcome (nothing is written).
- Transitions happen **only** in two actions (owned by this spec so specs 4 and 5 can build in parallel), each in a transaction with `lockForUpdate()` on the take:
  - `ApplyTakeSnapshotAction::execute(Take $take, GenerationSnapshotDTO $snapshot): TransitionOutcome`: provider-driven transitions (webhook, reconcile, create response). On entering a terminal state it settles (succeeded) or voids (failed) the usage record in the same transaction (spec 3-03), so it happens exactly once.
  - `FailTakeAction::execute(Take $take, string $code, string $message): TransitionOutcome`: local failures; voids the usage in the same transaction. Allowed for a definitive rejection, `cancelled` (spec 4, a pending take in the capacity state only), `provider_busy` / `submit_timeout` when the 30-minute window ends, and `internal`. Both actions set `last_event_at`.
- **Invariant (D-025, D-049):** an ambiguous create (timeout, connection error, 5xx) keeps the take `pending` (`last_submit_error_kind = ambiguous`) and retries with the same key for up to 30 min; capacity responses (429/503) do the same with kind `capacity`. A local `failed` means Pixelforge provably never created it, or a window expired. **Two codes:** `submit_timeout` (ambiguous window expired): a later `generation.succeeded` is `orphan_success` (take stays `failed`, usage stays voided, cost absorbed, `attention_reason = orphan_success`). `provider_busy` (definite non-creation): a later success is impossible, so it is `ignored_conflict`. Any other terminal→terminal mismatch is `ignored_conflict`.

## Other enums
`TakeSize` (`1024x1024`, `1536x1024`, `1024x1536`), `TakeQuality` (`low`, `medium`, `high`), `TakeSource`, `Provider` (`pixelforge`, `anthropic`), `UsageStatus` (`open`, `settled`, `voided`), `LedgerEntryType` (`purchase`, `promo_grant`, `charge`, `adjustment`), `SuggestionStatus` (`pending`, `proposed`, `applied`, `discarded`, `failed`), `FailureCode` (list above), `TransitionOutcome`, `WebhookOutcome`, `DenialReason` (3-01).

## Tests
- Full transition matrix (allowed and forbidden); every `TransitionOutcome` incl. `ignored_conflict`, `invalid_snapshot` and the `submit_timeout` (orphan) vs `provider_busy` (conflict) split; replayed create returns the existing take.
- Migration up/down on Postgres in CI; migration test that deleting a customer with takes/ledger rows is blocked.
- CHECK constraints reject invalid rows (raw insert tests), including the succeeded/failed/terminal CHECKs.

## Issues
1. Migrations for customers columns, projects, suggestions (full column list above), takes (+ checks, indexes, restrict FKs, partial unique `suggestion_id`), `webhook_events`, `webhook_deliveries` (Gate B, with 3-03's money tables).
2. Enums with state machine + outcomes + labels/colors.
3. `ApplyTakeSnapshotAction` and `FailTakeAction` with outcomes + tests (consumed by specs 4 and 5). Their dispatches of `ReconcileTakeJob` (spec 5), `PersistTakeImageJob` (spec 4) and the `TakeUpdated` event (spec 5) target **interface stubs shipped here**: empty classes with the final names and constructor signatures (`App\Jobs\ReconcileTakeJob`, `App\Jobs\PersistTakeImageJob`, `App\Events\TakeUpdated`) that the later specs fill in; 3-02 tests assert the dispatch with `Queue::fake()`/`Event::fake()` and nothing in spec 3 depends on spec 4 or 5 code.
4. Models with casts, relations, `$fillable` none (explicit `forceFill` in actions, house style).
