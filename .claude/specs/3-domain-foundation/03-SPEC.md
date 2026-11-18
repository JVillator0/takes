# 3-03 · Money and the multi-provider ledger

Status: **Approved** · Milestone: **M3 Domain foundation** · Depends on: 3-02 · Unblocks: 4, 5, 6, 7, 8 · Parallel: no (lead)
Reviews: product ☑ · technical ☑ · Decisions: D-002, D-009, D-021, D-023, D-024, D-025, D-042, D-051, D-055, D-059

## 1. Unit
Integer **nanodollars** (1 USD = 1,000,000,000), PHP `int`, Postgres `bigint`. Max ≈ $9.2 billion. No floats anywhere in money code or in rate config (arch test + a grep over `config/ai-pricing.php`).

`App\Support\Money\Nanodollars` (`final readonly`, wraps `int`):
- `fromDecimalString('0.373875000')`: string parsing, ≤ 9 fraction digits, rejects anything else.
- `perMillionFromDecimalString('12.50')` → nanodollars per 1M tokens (`12_500_000_000`). Pixelforge rates (decimal strings per 1M, from the generation object / `GET /v1/pricing`) and `config/ai-pricing.php` (strings, e.g. `'0.10'`) both go through it.
- `fromInt(int|string)`: wraps an integer (or integer string) from SQL sums; spec 7 uses it, never `fromString`.
- `plus()`, `minus()`, `isNegative()`, `greaterThanOrEqual()`.
- `withMarkup(int $bps = 2500)`: see §3.
- `toDecimalString()` → `"0.074037500"`; `format(int $decimals = 4, RoundingMode)`: rounded display for lists, cards, pill (D-021); `formatExact()`: money pages and admin, trailing zeros trimmed, never fewer than 2 decimals (D-042). Both integer-only and covered by boundary tests.

## 2. Provider cost
For any provider, a usage has **units** (token counts by kind) and a **rates snapshot** (nanodollars per 1M tokens by kind):
```
cost = ceil( Σ(units_k × rate_k) / 1_000_000 )
```
- Pixelforge kinds: `input_text`, `input_image`, `output`, `reasoning`; rates are the generation's `rates` (fixed at creation, present in the response and in every webhook's `data.object`). The estimate and the hold use the rates from the `getPricing` call used for that create; they are stored in `usage_records.rates` at open and **overwritten at settle** with the generation's own `rates` (D-055).
- Anthropic kinds: `input_text`, `output` only (no cache kinds until used); rates from `config/ai-pricing.php` snapshotted at call time (Haiku 5.5: $0.10 / $0.50 per 1M). The maximum cost is computed **per request by spec 8's formula** (D-055), not a fixed bound; every Anthropic figure is `tokens × (100 | 500) nanodollars`, charge = ×1.25.
- With current rates every term divides exactly; the ceil only guards future rates and logs a warning when used.
- **Cost check at settle:** if the snapshot carries a cost, or `GET /v1/usage` later disagrees, we settle with the computed value from units × the generation's rates and set `usage_records.flags` `cost_mismatch` (a Needs attention entry) (reconciliation, D-034, surfaces it).

## 3. Charge (README rule, verbatim)
```
charge = ceil( cost × (10_000 + markup_bps) / 10_000 ),  markup_bps = 2500
```
With current rates the result is always exact (every per-token rate in nanodollars is a multiple of 4). Rounding, if it ever happens, is at most 1 nanodollar in the platform's favor. Failed work: cost 0, charge 0.

## 4. Estimates
Pixelforge (spec 4) and Anthropic (spec 8) estimators return `Estimate { minCost, maxCost, minCharge, maxCharge }`. The **hold** is always `maxCharge`.
Pixelforge max: `text_max = ceil(ceil(chars/4) × 115/100)`, refs × 765, output from table, reasoning max. **`chars` = `mb_strlen($prompt)`** (Unicode characters, as the docs state; not bytes, not UTF-16 units); the 4000 limit uses the same count. Tests with accents and emoji; before M4, one real prompt on the dev key confirms the count. Verified against the docs' worked example (400 chars, high, 1536×1024, 2 refs ⇒ cost 373,875,000).

## 5. Tables
### `usage_records` (one per billable provider call)
| column | notes |
|---|---|
| id ulid | |
| customer_id | FK restrict |
| provider | `pixelforge`, `anthropic` |
| billable_type / billable_id | `ulidMorphs`; both `Take` and `Suggestion` use ulid ids; morph map in `AppServiceProvider` (no class names in the DB); unique pair |
| model | e.g. `claude-haiku-5-5`, null for Pixelforge |
| status | `open`, `settled`, `voided` |
| max_cost_nanos, max_charge_nanos | from the estimate; **`max_charge_nanos` of an open usage is the customer's hold** (D-024) |
| units jsonb, rates jsonb | rates snapshot at open, **overwritten at settle** with the generation's rates; units set at settlement |
| input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens | integer null until settle (Anthropic: `input_text_tokens`, `output_tokens`); cheap sums for A04 vs `/v1/usage` (D-055) |
| min_cost_nanos, min_charge_nanos | from the estimate (spec 7 uses them for the estimate range) |
| flags | jsonb array default `[]`: `over_estimate`, `cost_mismatch`, `orphan`; durable backing of Needs attention (D-054) |
| provider_cost_nanos, charge_nanos | null while open; 0 when voided |
| absorbed_cost_nanos | null unless a voided usage's work was still billed by the provider: an orphan success (units × the generation's rates, #102) or an unusable AI response (units × the open rates, spec 8). Stored with its units, tokens, `completed_at` and `billing_date`. Never in the ledger. Read by `billing:verify --usage` (Pixelforge) and the daily AI budget (Anthropic). Admin-only (D-023, D-087) |
| markup_bps | snapshot (2500) |
| external_id | `gen_…` / Anthropic message id |
| completed_at, billing_date | provider completion time; `billing_date` = its UTC date |
| timestamps | |
CHECKs: token columns ≥ 0; open ⇒ cost/charge null; settled ⇒ not null; voided ⇒ both 0; `absorbed_cost_nanos` null unless voided, then ≥ 0 with a `billing_date` (D-087); all amounts ≥ 0. Index `(provider, billing_date)`, `(customer_id, billing_date)`, partial `(customer_id) where status = 'open'`.

### `ledger_entries` (append-only, real money only; **no hold rows**, D-024)
`id` bigint · `customer_id` · `type` (`purchase`, `promo_grant`, `charge`, `adjustment`) · `amount_nanos` (signed) · `usage_record_id` null · `purchase_id` null (ulid FK `purchases`) · `admin_id` null · `reason` null · `created_at`.
- Unique `(usage_record_id)` where `type = 'charge'` ⇒ a usage is charged **once**. Unique `(purchase_id)` where `type = 'purchase'`. Unique `(customer_id)` where `type = 'promo_grant' and reason = 'welcome'` ⇒ one welcome credit (D-019).
- Sign CHECKs: purchase/promo_grant > 0; charge < 0; adjustment ≠ 0.
- Model forbids update/delete (throws).

Cached columns (spec 3-02): `customers.balance_nanos` = Σ ledger `amount_nanos`; `customers.held_nanos` = Σ `max_charge_nanos` of the customer's open usages; **available = balance − held**. Updated only by the actions below in the same transaction (invariant test + admin widget, A10). Statements do not exist (D-029); invoices cover purchases only (spec 6).

### `purchases` (migrated here under Gate B, D-051)
`id` **ulid** (so `ledger_entries.purchase_id` and spec 6's `invoices.purchase_id` are ulid FKs) · `customer_id` FK restrict · `reference` · `amount_nanos` (> 0) · `status` (`pending`, `paid`, `failed`) · `paid_at` null · `idempotency_key` · timestamps; **unique `(customer_id, idempotency_key)`**. **Packs:** config key `takes.purchase_packs_usd` = `[5, 10, 25]` (integer dollars, no env, appended to `config/takes.php`, defined only here); the request carries `package` = one of them, and specs 4 (modal) and 6 only read it. `PurchaseCreditsAction` and `POST /billing/purchases` are **built here (issue 6)**: validates the package and idempotency key, creates the purchase as `paid` (simulated payment), writes one `purchase` ledger entry via `RecordLedgerEntryAction` in one transaction, **no invoice**. Spec 4 issue 1 consumes it (minimal modal); spec 6 adds only `invoices` and `invoice_counters` and calls `IssueInvoiceAction` after the purchase when present.

## 6. The three money actions (`App\Actions\Billing`)
Each action starts with `assert(DB::transactionLevel() > 0)` (the caller's transaction) and locks **only the customer row** (`lockForUpdate`), then re-reads the usage status under that lock.

| Action | When | Steps (inside the caller's transaction) |
|---|---|---|
| `OpenUsageAction` | before calling a provider | lock customer → `available ≥ maxCharge` else `InsufficientCreditsException` → create usage `open` (rates, max cost/charge) → `held += maxCharge` |
| `SettleUsageAction` | provider work succeeded | lock customer → re-read usage, must be `open` → compute cost from units × rates → charge → ledger `charge (−charge)` → `balance −= charge`, `held −= maxCharge` → usage `settled`, billing_date |
| `VoidUsageAction` | provider work failed / rejected | lock customer → re-read usage, must be `open` → `held −= maxCharge` → usage `voided`, cost 0, charge 0; with units the provider still billed: units, tokens, `absorbed_cost_nanos` and `billing_date` (also on an already voided usage without one, e.g. an AI result after expiry) |

`RecordLedgerEntryAction` is the only writer of `ledger_entries` and `balance_nanos` (purchase, promo_grant, adjustment, and the charge inside Settle).

- Settling or voiding a non-open usage is a no-op (idempotent), never an error that could break a webhook.
- **Lock order, the only one:** take|suggestion → customer; usage is re-read under the customer lock, never locked separately. The caller locks the take/suggestion first; the money actions then lock the customer only. Any future caller follows the same order.
- **Actual > hold** (defensive; reachable only through estimator bugs or stale pricing): we still charge the actual amount (correct money first); available may go below zero by that difference; an `over_estimate` log + `usage_records.flags` entry (Needs attention, a bug signal). Customer-facing state: when `available < 0` the composer and balance show the 2-01 string for the negative-balance state (interpolates $X; no literal here, D-057); `TakePolicy` denies with `insufficient_credits`. Tested.
- **Orphan success (D-025):** a late success for a take failed locally with `submit_timeout` is *not* settled: usage stays voided, cost absorbed, `orphan` flag, outcome `orphan_success` (3-02). Pixelforge's `/v1/usage` includes that cost; spec 7 shows it as its own group (D-058).

## 7. Display (D-021, D-023)
Server formats; the frontend never does money math. Lists: 4 decimals (estimate min floor, max ceil, charges half-up). **Money pages (take detail, billing page, invoices) and admin: exact amount, trailing zeros trimmed but never fewer than 2 decimals** (e.g. `$5.00`, `$0.0735`; D-042). The "no 2 decimals" ban in 2-01 applies only to rounded display on lists, cards and the pill. **Totals are rounded from the exact sum, never from rounded parts**, so a total may differ by $0.0001 from the sum of displayed lines (footnoted on the sheets). Customers see prices only (estimate range, amount charged, spend by feature: images, AI suggestions); cost, tokens, provider names and margin are admin-only.

## 8. Adding a provider (skill `money-invariants`)
New enum case + units kinds + rates source + estimator + call site wrapped in Open → Settle/Void. Nothing else in billing changes: that is the point of the multi-provider ledger. The admin Pricing page lists every kind in use: Pixelforge `input_text`, `input_image`, `output`, `reasoning` and Anthropic `input_text`, `output`.

## `billing:verify` (D-059)
`php artisan billing:verify` checks the invariants: Σ ledger = balance, Σ open `max_charge` = held, one charge per usage, no open usage on a terminal take, settled/voided amounts consistent; `--usage` also compares per-day Pixelforge cost with `getUsage` (3-04). Non-zero exit on any violation. Used by specs 9 and 10.

## Tests (unit + feature)
- Worked example, every output table entry, reasoning bounds, markup exactness for all kinds, ceil guard with a synthetic odd rate; `mb_strlen` with accents/emoji.
- `Nanodollars` parsing/formatting round-trips; rejects floats and > 9 decimals; config rate strings.
- Open/Settle/Void happy paths; settle twice ⇒ one charge; void after settle ⇒ no-op; settle after void ⇒ no-op; actions throw outside a transaction.
- **Concurrency** (serial Pest group, `DatabaseMigrations` or committed seed, not `RefreshDatabase`): spawn two `Process::run` artisan commands (or `Concurrency::run`) against the same test DB: two opens for a balance that covers one ⇒ one `InsufficientCreditsException` and one open usage; two settles of different takes of one customer in parallel ⇒ balance exact.
- Invariant: after a randomized sequence, Σ ledger == balance and Σ open `max_charge` == held.
- `PurchaseCreditsAction`: balance rises by the amount, replay with the same key creates nothing. Welcome grant unique per customer; `purchases` unique `(customer_id, idempotency_key)`; `billing:verify` passes on a clean dataset and fails on an injected drift.

## Issues
1. `Nanodollars` + tests.
2. `usage_records`, `ledger_entries`, `purchases`, customer cached columns + constraints (migrations in Gate B, D-052).
3. `RecordLedgerEntryAction`, `OpenUsageAction`, `SettleUsageAction`, `VoidUsageAction` + tests (incl. parallel-process test).
4. `TakeEstimator` (integer rules + fixtures incl. the docs worked example) + `config/ai-pricing.php` (string rates, source URL and date). Single owner of both.
5. `billing:verify` command (+ `--usage`) and tests.
6. `PurchaseCreditsAction` + `POST /billing/purchases` (permission `credits.purchase`, `throttle:5,1`) + purchase ledger entry (no invoice) + tests (idempotent replay, one ledger entry, unique key, throttle).
