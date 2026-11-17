# 6 · Billing and invoicing

Status: **Approved** · Milestone: **M6 Billing** (stretch 2 + invoicing) · Depends on: 4, 5 · Worktree: **a** · Parallel with: 8, 7
Reviews: product ☑ · technical ☑ · Wireframes: P05-A, P08, A05, A10 · Decisions: D-019, D-022, D-023, D-024, D-026, D-029, D-032, D-037, D-042, D-051, D-052, D-057

## Goal
A customer-facing billing page (balance, on hold, spend by feature, activity) and sequential invoices per purchase, all derived from the ledger of spec 3-03. Holds and the ledger already exist (spec 3); the welcome credit and the minimal "Buy credits" modal already exist (spec 4, D-026). This spec adds the invoice issued by each purchase, the billing page and the printable invoice.

> **No monthly statements** (D-029) and **no provider cost, tokens, provider names or margin** anywhere a customer can see (D-023).

## Money model recap (D-024)
- Ledger types: `purchase`, `promo_grant`, `charge`, `adjustment`. Holds are **not** ledger rows: an open usage record carries `max_charge_nanos`.
- `customers.balance_nanos` = Σ ledger. `customers.held_nanos` = Σ open usage holds. **Available = balance − held.** Liability for admin (A09) = Σ `balance_nanos` (held money is still the customer's until charged).
- Customer routes use `auth:customer` + `Auth::shouldUse('customer')` (D-032).

## Purchases (simulated; `purchases` table and `PurchaseCreditsAction` are built in 3-03 (its own issue: action + route + purchase ledger entry, no invoice, D-051) and consumed by spec 4's modal; this spec adds the invoice call)
- Names are fixed by D-051: `PurchaseCreditsAction`, `POST /billing/purchases`, table `purchases` (id, customer_id, reference, amount_nanos, status, paid_at, idempotency_key, unique(customer_id, idempotency_key)), all owned by 3-03 (migration under Gate B, D-052). Spec 6 adds only `invoices` and `invoice_counters`.
- Packages: `takes.purchase_packs_usd` (`[5, 10, 25]`, defined in 3-03; read only here). `POST /billing/purchases {package, idempotency_key}` (permission `credits.purchase`, throttle 5/min; both built in 3-03 issue 6).
- `PurchaseCreditsAction`, one transaction, **lock order: customer row → invoice counter** (never reverse). Without issue 3 the action stops after the ledger entry (no invoice):
  1. Lock the customer.
  2. Look up `(customer_id, idempotency_key)`; if found, return the existing purchase (a concurrent double-click waits on the lock, then replays; no exception, no second invoice).
  3. Insert `purchases` (`reference = SIM-<ulid>`, `amount_nanos`, `status = paid`, `paid_at`) → ledger `purchase (+amount)` → `IssueInvoiceAction` (added by issue 3).
- Idempotency: unique `(customer_id, idempotency_key)` (not global, so no cross-customer collision or leak). The key is a uuid generated when the modal opens; a second purchase needs a new modal open.
- No card fields; copy from 2-01 (`billing.simulated_notice`, `billing.pay`).
- UI: **Pay** is disabled while processing; success = toast (2-01 billing copy), the new available amount updates in place, and a **View invoice** link (only once invoices exist).

## Invoices (D-022, D-029; lean)
- One invoice per purchase, nothing else. Tables: `invoices` (`number` unique, `customer_id`, `purchase_id` unique (ulid FK to `purchases`, 3-03), `currency` USD, `total_nanos`, `status` paid, `issued_at`, billing snapshot name/email) and `invoice_counters` (`year` PK, `last_number`). **No `invoice_lines`** (one purchase = one line; the line description is derived, text from 2-01). No tax.
- Numbering `INV-{YYYY}-{NNNNNN}`, gapless per year. `IssueInvoiceAction`: `INSERT … ON CONFLICT (year) DO NOTHING`, then `SELECT … FOR UPDATE`, then increment (or one `UPDATE … RETURNING` after the upsert) so the first purchase of a year works and two concurrent first purchases cannot race. Gapless numbering serialises purchases, acceptable at this scale (noted in the README).
- Printable HTML view `/billing/invoices/{invoice}` (policy: owner only), print CSS, "Print / Save as PDF" via the browser. No PDF library.
- Amounts render **exact** (trailing zeros trimmed but never fewer than 2 decimals, e.g. $5.00, $0.0735; rule in 2-01 and 3-03 §7), never the 4-decimal list rounding (D-042).

## Billing page (P08)
- Money page (D-042): all amounts on this page and on invoices are **exact** (trailing zeros trimmed, never fewer than 2 decimals); the rounded 4-decimal display applies only to lists, cards and the pill elsewhere.
- Cards: **Available**, **On hold**, **Spent this month** split by feature (Images, AI suggestions); buy buttons.
- Tabs: **Activity**, **Invoices** (the Invoices tab appears only when issue 3 ships).
- Activity is driven from `usage_records` joined to the ledger, **one line per usage**: status (open = on hold, charged, voided = not charged; strings from 2-01) + feature + take link. Ledger rows appear only for `purchase`, `promo_grant` and `adjustment`. No hold/release reconstruction.
- Running-balance column = Σ ledger up to that row (excludes open holds); footnote text from 2-01 (balance excludes amounts on hold). Rows sorted by timestamp; the demo dataset must be chronologically consistent (D-037).
- "Spent this month" buckets by ledger `created_at` (UTC month) so it reconciles to the balance; admin reports bucket by provider `billing_date` so they reconcile to `/v1/usage`. Labels say "by settlement date" vs "by provider billing day"; the two can differ for a take settled after midnight UTC.
- All amounts come from the single demo dataset shared with the seeder and every wireframe (D-037).

## Admin side (built in spec 7)
Ledger, purchases, invoices resources; *Adjust credits* action creates `adjustment` entries with admin + reason.

## Tests
- Purchase creates exactly one purchase, one ledger entry and one invoice with the next number; replay with the same key creates nothing new.
- Two parallel requests with the same key: one purchase, one invoice, one ledger entry. Same key from two customers: both succeed.
- Billing page renders exact amounts (e.g. a value whose 4-decimal rounding differs); open usage shows the on-hold status.
- Concurrent purchases produce consecutive, gapless numbers, including the first purchase of a new year in parallel.
- Blocking: available < max ⇒ cannot generate; after purchase ⇒ can.
- Activity shows one line per usage with the final amount (open, charged, voided).
- Invoice exact amount renders correctly for values whose 4-decimal rounding would differ.
- A take settled after midnight UTC appears in the settlement-day month of "Spent this month".
- A customer route test hits the page as a customer under `auth:customer` (D-032).

## Issues (ordered by brief value)
1. Purchase replay and double-click test on the existing 3-03 `PurchaseCreditsAction`; the success toast and in-place balance are spec 4's minimal modal (D-026), not rebuilt here. The *View invoice* link is added to that success state in issue 3. No invoice call yet; purchases work without invoices.
2. Billing page (cards, Activity from usage records; no Invoices tab yet).
3. Invoices: `invoices` + `invoice_counters`, `IssueInvoiceAction` hooked into the purchase action (lock order above), printable view, Invoices tab, View invoice link, and the invoice concurrency / first-of-year tests.

Cut line (single definition): issues 1-2 are required; issue 3 is the droppable item. If it is cut, purchases keep working with no invoice and the Invoices tab and View invoice link are hidden. Welcome credit lives in spec 4 (D-026). Customer-facing copy comes from 2-01 and `lang/en/*.php` (D-057); this spec does not restate strings beyond the labels above.
