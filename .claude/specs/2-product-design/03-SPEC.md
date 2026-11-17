# 2-03 · Admin console screens

Status: **Approved** · Milestone: **M2 Product design** · Depends on: 2-01 · Unblocks: 7
Reviews: product ☑ · technical ☑
Wireframes: `wireframes/a02…a11*.png`. Filament built-ins (login, MFA, password reset, standard list/view layouts, Users, Roles, Activity log) are used unchanged and not designed (D-017).

| Sheet | Screen | Type | Content |
|---|---|---|---|
| A02 | Dashboard | **Custom, wireframe (normative)** | Range filter (Today / Last 30 days / This month (UTC)); stats: customer spend, provider cost, profit, margin, takes + failure rate, running now, AI budget used today vs `AI_DAILY_BUDGET_USD`, active holds + credits liability; ECharts line (customer spend / provider cost / profit per UTC day); donut cost split by provider; *Needs attention* table. Header shows "Updated 12:03 UTC" + Refresh. |
| A03 | Billing › Reports | **Custom, wireframe (normative)** | Toggle **by day / by customer** + customer filter + provider filter (D-035). Day view: takes, failed, AI suggestions, spend, Pixelforge cost, Anthropic cost, profit, margin, reconciled badge linking to A04. By-customer view: customer, takes, failed, suggestions, spend, provider cost, profit, share of spend. Days with no activity are omitted; margin shows "-" when spend is 0. CSV grouped by day × customer, with nanodollar (exact) and dollar columns. Footnote: profit counts promo and adjustment credits consumed as spend with no cash received; a column "Promo/adjustment credits consumed" shows how much. |
| A04 | Providers › Pixelforge usage | **Custom, wireframe (normative)** | See Reconciliation below. |
| A05 | Customers › View | Standard view; only **Adjust credits modal** and header stats are wireframed | Header stats: Available, On hold (same model as the public app, D-024), takes, spend. Relation tabs are Filament defaults. *Adjust credits* (audited, reason required). |
| A06 | Studio › Take view | **Custom infolist, wireframe (normative)** | See Take view sections below. |
| A07 | IA overview | Documentation | Clusters, resources, roles, plugins, panel conventions. |
| A08 | Authorization | Documentation | Two guards (`web` admins, `customer` customers, D-012, D-032), roles, permissions, policy flow with reason codes, lock re-check (D-015). |
| A09 | Tables: Customers, Studio | Data spec, moved to spec 7 | Columns, filters, actions, header widgets with formulas, default sort. |
| A10 | Tables: Billing | Data spec, moved to spec 7 | Ledger (append-only + invariant widget), purchases, invoices (per purchase, no statements, D-029). |
| A11 | Tables: Providers, System | Data spec, moved to spec 7 | Webhook events, **webhook deliveries (D-027)**, Anthropic usage, pricing drift. Users, Roles and Activity log rows are dropped (Filament defaults). |

A09-A11 stop being sheets of record: their column, source, filter and widget-formula tables become tables in spec 7. The PNGs stay as illustrations only.

## Metric glossary (single source for every sheet and report)
| Term | Definition |
|---|---|
| Customer spend | Σ settled `charge_nanos` (charges on the ledger). |
| Provider cost | Σ `provider_cost_nanos` of settled usage records (Pixelforge + Anthropic). |
| Profit | Customer spend − provider cost. |
| Margin | Profit / customer spend (shown "-" when spend = 0). Margin is on **spend** (20.0% = 2000 bps), while the markup is 25% **on cost**: charge = cost × 1.25. Each settled record must have markup 25% of cost (margin 2000 bps); a row that differs is an invariant alert. |
| Takes | Takes created in the range (includes failed). Stat labelled "Takes". |
| Generations (charged) | Settled Pixelforge usage records; failed takes are free and not counted. |
| Credits liability (Outstanding credits) | Σ `balance_nanos` of all customers (held money is still the customer's); held and available are shown separately. |
| Active holds | Σ `max_charge_nanos` of open usage records. |
| Anthropic cost | Σ over suggestions of (input tokens × input rate + output tokens × output rate), from the stored rates snapshot. |

All charts, stats, donuts and tables are regenerated from the demo dataset (D-037) using these definitions; labels say "Customer spend" everywhere (never "Revenue"). Per-suggestion charge is cost × 1.25 (e.g. 1,480 in / 212 out at $0.10 / $0.50 per M tokens = $0.000254 cost, $0.0003175 charge).

## Reconciliation (A04)
- Per UTC billing day: our settled generations, cost and the four token sums vs `GET /v1/usage`; difference per field, so a mismatch shows which token kind differs. 9 decimals.
- Day status: **Match**, **Mismatch**, **Pending** (current UTC day, or any day with open takes: shows "N in flight", never Mismatch, D-034) and **Provider unavailable** (banner "Could not reach Pixelforge. Last fetched 12:03 UTC" with cached figures).
- Whether `/v1/usage` counts failed generations is not documented: verify against the real API on day one and record the finding in spec 7; the comparison excludes failed takes accordingly.
- Drill-down (D-034) lists both our unsettled takes and Pixelforge generations from `GET /v1/generations` (status + cursor, matched by `metadata.app_take_id`) that we do not have, in groups: theirs-not-ours, ours-not-settled, amount-differs and **"Orphan success (cost absorbed)"** (D-058). Pixelforge `/v1/usage` includes an orphan's cost while our settled sum does not, so it is never shown as a plain mismatch and links to the take. Action **Reconcile now** (same action as A06, D-058) runs the same reconcile used elsewhere.
- Dashboard *Needs attention* offers **Review day** linking to A04 for a day mismatch; **Reconcile now** exists on A06 (per take) and in the A04 drill-down.

## Take view sections (A06)
1. **Summary**: ids (take, Pixelforge `gen_…`, idempotency key), status, source, parent (link), parameters, links to customer and project.
2. **Money**: as drawn (integer nanodollars, rates snapshot, tokens) + usage record status (open / settled / voided), `billing_date`, ledger rows for this take (charge only; the hold shows as `max_charge_nanos`, D-024).
3. **Timeline**: submitted, started, completed with Pixelforge timestamps, state transitions, `submit_attempts`, `last_submit_error`.
4. **Failure**: `failure_code`, message (when failed).
5. **Webhook deliveries** (D-027): every delivery with attempt and outcome (`applied`, `duplicate`, `ignored_stale`, `ignored_same_state`, `ignored_conflict`, `orphan_success`, `ping`, `unknown_take`, `ignored_type`, `invalid_snapshot`; enums owned by 3-02, D-049) + payload modal (raw payload).
6. **Reconcile now**: notification with the outcome ("No change" or "Applied: succeeded, charged $x").

## Rules for the console build
- Widgets never poll (`$pollingInterval = null`), so the database can sleep on Laravel Cloud (D-038). Dashboard and Needs attention show their age ("Updated 12:03 UTC") with a Refresh action.
- All money columns: bigint nanodollars, summed in SQL, formatted by `Nanodollars::formatExact()` (admin shows exact amounts, D-042).
- Every resource, page and custom action has a Shield permission; the auditor role sees but never writes.
- Activity log: every write action is recorded with the admin. A **reason is required only for Adjust credits and Restrict customer**; Reconcile now logs its outcome automatically.
- *Needs attention* is a derived view over DB state (durable flags `takes.attention_reason`, `usage_records.flags`, D-054), one entry per type: stuck, over_estimate, unknown_take, orphan_success, invalid_snapshot, cost_mismatch, reconcile_exhausted, internal_failure, ledger_drift, margin_invariant (markup ≠ 25% of cost, i.e. margin ≠ 2000 bps), day_mismatch. **Stuck** is defined once in spec 7 (D-053: queued/processing with no provider update > 15 min via `takes.last_event_at`, or pending > 35 min); this spec does not restate thresholds. The sweeper (D-036) runs on dashboard load.
- The full-history demo dataset (D-037, wireframe figures) is seeded only for local, CI and screenshots. In production the showcase customer's history comes from real smoke-test calls and the Demo customer has a $10 purchase and no takes (D-045); "Try a demo account" customers are ephemeral.

## Acceptance criteria
- [ ] A02, A03, A04, A06 and the Adjust credits modal are implemented as drawn; spec 7 tables replace A09-A11.
- [ ] Every figure on the sheets follows the glossary and ties to the demo dataset (A02 donut, A03 totals and A11/A09 token counts agree).
- [ ] A04 never shows Mismatch for today or for days with open takes.
- [ ] Logged in as auditor, no write action is visible.

## Issues (scope moved)
- Spec 7 gains the A09-A11 data-spec tables, the webhook deliveries table (D-027), the by-customer report and the **Reconcile now** action in the A04 drill-down.
