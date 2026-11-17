# Money rules (checklist)

Source: spec 3-03 (money and ledger), 3-02 (state machine), 8 (AI suggestions), decisions D-002, D-015, D-021, D-023, D-024, D-025, D-033, D-042, D-055, D-059.
Applies to: `app/Support/Money`, `app/Actions/Billing`, `app/Actions/Takes`, `app/Actions/Suggestions`, `app/Jobs`, `app/Http/Resources`, `app/Policies`, `config/takes.php`, `config/ai-pricing.php`, `database/migrations`, and any code that reads or writes an amount.

Every rule has an ID, the violation reviewers reject, and the test that enforces it. A PR that adds a rule or a test path updates this file in the same PR. A test path listed here that does not exist yet is created by the spec issue that first touches the rule; until then the reviewer checks the rule by reading the diff.

Reviewers: a violation of any rule below is a **critical** finding unless the rule says otherwise.

## Unit and arithmetic

- [ ] **M-01 Integer nanodollars.** Every amount is an `int` of nanodollars (10⁻⁹ USD) in PHP and a `bigint` in Postgres, wrapped in `App\Support\Money\Nanodollars` when it travels. Columns end in `_nanos`.
  Reject: `float`, `decimal`, `double`, cents, or a USD string stored or passed between layers.
  Tests: `tests/Unit/Support/Money/NanodollarsTest.php`.
- [ ] **M-02 No floats in money code.** No `float` type, `floatval`, `(float)`, `round(`, `number_format(`, `/` on amounts, or `bcmath` in `App\Support\Money`, `App\Actions\Billing`, `App\Actions\Takes`, `App\Actions\Suggestions`. Display rounding exists only in `Nanodollars::format()`, built with `intdiv` + remainder.
  Tests: `tests/Arch/ArchitectureTest.php` (money float scan); `tests/Unit/Support/Money/NanodollarsTest.php` (rounding boundaries x.49999, x.5, exact).
- [ ] **M-03 Rates and USD config are strings.** Rates in `config/ai-pricing.php` and USD env values (`WELCOME_CREDIT_USD`, `AI_DAILY_BUDGET_USD`) are decimal strings parsed once through `Nanodollars::fromDecimalString` / `perMillionFromDecimalString` (D-066). Nothing downstream sees a float or a USD string.
  Tests: `tests/Arch/RepositoryTest.php` (float scan over `config/`); `tests/Unit/Config/TakesConfigTest.php` (`AI_DAILY_BUDGET_USD=0.5` gives `500_000_000`).
- [ ] **M-04 SQL sums go through `Nanodollars::fromInt(int|string)`.** Postgres `SUM(bigint)` returns `numeric`, which PDO gives as a string; never cast it with `(int)` after a float, never `(float)`.
  Tests: `tests/Unit/Support/Money/NanodollarsTest.php` (`fromInt` with integer strings).
- [ ] **M-05 Cost and charge formulas.** `cost = ceil(Σ(units_k × rate_k) / 1_000_000)`; `charge = ceil(cost × (10_000 + markup_bps) / 10_000)` with `markup_bps = 2500` snapshotted on the usage record. Integer arithmetic only. Failed work: cost 0, charge 0.
  Tests: `tests/Unit/Billing/TakeEstimatorTest.php` (docs worked example: 400 chars, high, 1536×1024, 2 refs ⇒ cost 373,875,000; markup exactness for every kind; ceil guard with a synthetic odd rate; `mb_strlen` with accents and emoji); `tests/Feature/Billing/SettleUsageActionTest.php`.

## Ledger and holds

- [ ] **M-06 Holds are state, not ledger rows (D-024).** An open usage record's `max_charge_nanos` is the hold. `customers.held_nanos` = Σ open holds, `customers.balance_nanos` = Σ ledger, `available = balance − held`. Ledger types are exactly `purchase`, `promo_grant`, `charge`, `adjustment`.
  Reject: a `hold` / `release` ledger type, a ledger row written on open or void, `available` stored as a column.
  Tests: `tests/Feature/Billing/LedgerInvariantTest.php` (randomized sequence ⇒ Σ ledger == balance and Σ open `max_charge` == held); `tests/Feature/Billing/BillingVerifyCommandTest.php` (passes on clean data, fails on injected drift).
- [ ] **M-07 One writer of the ledger.** `RecordLedgerEntryAction` is the only code that inserts `ledger_entries` or changes `customers.balance_nanos`; only `OpenUsageAction`, `SettleUsageAction`, `VoidUsageAction` change `held_nanos`. Ledger rows are append-only (the model throws on update and delete). Admin adjustments write an `adjustment` row, never edit a balance.
  Tests: `tests/Feature/Billing/RecordLedgerEntryActionTest.php`; `tests/Arch/ArchitectureTest.php` (no `balance_nanos` / `held_nanos` writes outside `App\Actions\Billing`).
- [ ] **M-08 Database backs the invariants.** Unique `(usage_record_id) where type = 'charge'` (a usage is charged once); unique `(purchase_id) where type = 'purchase'`; unique welcome `promo_grant` per customer; sign CHECKs (purchase/promo_grant > 0, charge < 0, adjustment ≠ 0); `usage_records` status CHECKs (open ⇒ cost/charge null; settled ⇒ not null; voided ⇒ both 0); `customers.held_nanos >= 0`; FKs on money rows are `restrict`, customers are never hard-deleted. Migrations are reversible.
  Tests: `tests/Feature/Database/MoneyConstraintsTest.php` (raw inserts rejected); `tests/Feature/Database/MigrationsTest.php` (up/down).

## Transactions, locks and settlement

- [ ] **M-09 Money actions run inside the caller's transaction.** `OpenUsageAction`, `SettleUsageAction`, `VoidUsageAction` start with `assert(DB::transactionLevel() > 0)` and never open their own outer transaction.
  Tests: `tests/Feature/Billing/MoneyActionsTest.php` (each action throws outside a transaction).
- [ ] **M-10 Lock order: take | suggestion → customer.** The caller locks the take (or suggestion) first with `lockForUpdate()`; the money actions then lock **only the customer row**; the usage record is **re-read under the customer lock, never locked separately**. Any new caller follows the same order.
  Reject: `lockForUpdate()` on `UsageRecord`; locking the customer before the take/suggestion; reading usage status before taking the customer lock and trusting it afterwards.
  Tests: `tests/Feature/Billing/MoneyConcurrencyTest.php` (group `concurrency`: two opens for a balance that covers one ⇒ one `InsufficientCreditsException` and one open usage; two settles of different takes of one customer in parallel ⇒ exact balance); `tests/Arch/ArchitectureTest.php` (no `lockForUpdate` on usage records).
- [ ] **M-11 Take transitions only in `ApplyTakeSnapshotAction` and `FailTakeAction`.** No other class writes `takes.status`. Both lock the take, set `last_event_at` and return a `TransitionOutcome`.
  Reject: `$take->status = …`, `update(['status' => …])` or `forceFill(['status' => …])` anywhere else (controllers, jobs, listeners, Filament actions, seeders outside factories).
  Tests: `tests/Feature/Takes/ApplyTakeSnapshotActionTest.php` (full transition matrix, every outcome); `tests/Feature/Takes/FailTakeActionTest.php`; `tests/Arch/ArchitectureTest.php` (status writes only in the two actions).
- [ ] **M-12 Settlement exactly once, inside the terminal transition.** A take's usage is settled (succeeded) or voided (failed) in the same transaction that moves the take to a terminal state, and nowhere else. A suggestion's usage is settled or voided only in `CreateSuggestionAction` T2 or by the sweeper's expiry (spec 8). Settling or voiding a non-open usage is a silent no-op, never an exception that could break a webhook.
  Tests: `tests/Feature/Billing/SettleUsageActionTest.php` (settle twice ⇒ one charge; settle after void ⇒ no-op); `tests/Feature/Billing/VoidUsageActionTest.php` (void after settle ⇒ no-op); `tests/Feature/Webhooks/WebhookIdempotencyTest.php` (duplicate delivery ⇒ one settlement).
- [ ] **M-13 Failed = free.** Every failure path voids the usage and releases the hold: provider `failed`, definite rejections, `cancelled`, `provider_busy`, `submit_timeout`, `internal`, revoked key (D-062), AI error/refusal/timeout.
  Tests: `tests/Feature/Takes/FailTakeActionTest.php`; `tests/Feature/Webhooks/WebhookOutcomeMatrixTest.php` (`failed` ⇒ not charged, hold released); `tests/Feature/Suggestions/CreateSuggestionActionTest.php`.
- [ ] **M-14 Orphan success is not settled (D-025, D-049).** A late `succeeded` for a take failed with `submit_timeout` leaves the usage voided, flags `orphan`, sets `attention_reason = orphan_success`. After `provider_busy`/`cancelled` it is `ignored_conflict`.
  Tests: `tests/Feature/Webhooks/WebhookOutcomeMatrixTest.php`.
- [ ] **M-15 Actual > hold still charges the actual amount.** Charge the computed amount, let `available` go negative by the difference, flag `over_estimate`; `TakePolicy` then denies with `insufficient_credits`.
  Tests: `tests/Feature/Billing/SettleUsageActionTest.php` (over-estimate case).
- [ ] **M-16 No HTTP call inside a database transaction.** Provider calls (Pixelforge create/get, Anthropic) happen with no transaction open and no row locked; the result is applied in a new transaction afterwards (submit job, reconcile job, `CreateSuggestionAction` T1 → call → T2).
  Reject: `Http::`, `PixelforgeClient`, or the AI agent called inside `DB::transaction()` or after `lockForUpdate()`.
  Tests: `tests/Feature/Billing/NoHttpInTransactionTest.php` (`Http::fake` callbacks assert `DB::transactionLevel() === 0` for submit, reconcile and suggestions).
- [ ] **M-17 No queue delay above 900 s.** Every `delay`, `backoff`, `release()` and `retryUntil` step is ≤ 900 s (Laravel Cloud managed-queue limit). Long waits are chains of short delays (reconcile: 60, 120, 300, 600, 840 s).
  Tests: `tests/Arch/RepositoryTest.php` (max job delay/backoff 900 s for every job).

## Idempotency and authorization

- [ ] **M-18 Every money-creating request is idempotent.** Takes: unique `(customer_id, idempotency_key)`, composer-minted uuid, reused as Pixelforge's `Idempotency-Key`; a replay returns the existing take with no new hold (D-033). Suggestions: unique `(customer_id, request_key)`. Purchases: unique `(customer_id, idempotency_key)`. AI apply: uuid v5 of the suggestion id + partial unique `takes(suggestion_id)`.
  Tests: `tests/Feature/Takes/CreateTakeIdempotencyTest.php`; `tests/Feature/Billing/PurchaseCreditsActionTest.php`; `tests/Feature/Suggestions/ApplySuggestionActionTest.php`.
- [ ] **M-19 Credits are state, checked twice (D-015).** Policies deny with `Response::deny` and a `DenialReason` code; the action re-checks `available ≥ maxCharge` under the customer lock (`InsufficientCreditsException`). Never grant or revoke a permission because of the balance.
  Tests: `tests/Feature/Takes/TakePolicyTest.php`; `tests/Feature/Billing/OpenUsageActionTest.php`.

## Display and disclosure

- [ ] **M-20 The server formats money; the frontend never does money math.** Resources and broadcast payloads send formatted strings (and, where needed, integer nanos as strings); Vue never parses, sums, rounds or formats an amount.
  Tests: `tests/Feature/Takes/TakeResourceTest.php` (money fields are strings).
- [ ] **M-21 Exact amounts keep at least 2 decimals.** Money pages (take detail, billing page, invoices) and the admin use `Nanodollars::formatExact()`: trailing zeros trimmed, never fewer than 2 decimals (`$5.00`, `$0.0735`).
  Tests: `tests/Unit/Support/Money/NanodollarsTest.php` (`formatExact` cases).
- [ ] **M-22 Rounded display is never 2 decimals (D-042, D-021).** Lists, cards, toasts and the balance pill use `Nanodollars::format(4, …)`: estimate min rounded down, max rounded up, charges half-up; exact value on hover. Totals are rounded from the exact sum, never summed from rounded parts.
  Tests: `tests/Unit/Support/Money/NanodollarsTest.php` (`format` boundaries); `tests/Feature/Takes/TakeResourceTest.php` (rounded fields have 4 decimals).
- [ ] **M-23 Customers never see provider cost, tokens, provider names or margin (D-023).** Customer-facing Inertia props, resources, broadcast payloads, lang strings and error messages expose prices only (estimate range, amount charged, spend by feature: images, AI suggestions). Provider cost, token counts, `pixelforge` / `anthropic`, markup and margin are admin-only.
  Tests: `tests/Feature/Customer/NoProviderLeakTest.php` (scans customer page props and the `TakeUpdated` payload for forbidden keys and words).

## Adding a billable provider

- [ ] **M-24 One money path.** A new provider adds an enum case, its unit kinds, a rates source, an estimator, and a call site wrapped in Open → Settle/Void. Nothing else in billing changes. Follow the `money-invariants` skill once it exists (M3).
  Tests: the provider's estimator test plus `tests/Feature/Billing/LedgerInvariantTest.php`.
