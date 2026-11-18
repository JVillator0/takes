# 7 · Admin console (Filament 5)

Status: **Approved** · Milestone: **M7 Admin console** (stretch 1) · Depends on: 3 (all shared tables are migrated in M3, D-052; Filament/Shield install at M7 start, D-048); Purchases and Invoices resources need 6, the Suggestions resource is built in spec 8 · Worktree: **c** · Parallel with: 4, 5, then 6, 8 · **Scope cut (D-080):** this milestone ships the foundation, Studio, Billing › Reports, Providers › Pixelforge usage and Webhook deliveries, and Customers (balances, Adjust credits). Dashboard widgets and Needs attention (issue 6, A02), the ledger invariant widget and Ledger entries (rest of issue 3), the Users, Purchases and Invoices resources (issue 8) and the Anthropic usage and Pricing pages (issue 9) are deferred; their acceptance criteria are out of the QA plan.
Reviews: product ☑ · technical ☑ · Wireframes: A02–A11 · Decisions: D-042, D-048, D-052, D-053, D-054, D-055, D-058, D-061, D-012, D-013, D-014, D-015, D-017, D-023, D-024, D-025, D-027, D-034, D-035, D-036, D-037

Filament, Shield, the MFA columns on `users` and admin role seeding are installed when M7 starts (D-048, issue 2); M3 only provides the customer guard, spatie roles/permissions and policies. Admin-only surface: provider cost, tokens, provider names and margin are shown here and never to customers (D-023).

## Panel (`App\Providers\Filament\AdminPanelProvider`, mirrors Buxy's ConsolePanelProvider)
- `id('admin')`, `path('admin')`, guard `web` (D-012), built-in `login()`, `passwordReset()`, `multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: fn () => ...)`. The closure is evaluated lazily with the authenticated user and returns false for the auditor role (D-013). Do not rely on a model method such as `requiresMultiFactorAuthentication()`; the closure on the panel is the single lever (see 3-01).
- `discoverClusters/Pages/Resources/Widgets` under `app/Filament/Admin/*`.
- `spa()`, `sidebarCollapsibleOnDesktop()`, `breadcrumbs(hasNavigationHierarchy: true)`, `globalSearch(false)` (no search box on the sheets), `databaseNotifications()` without polling.
- `navigation()` builder defines the whole sidebar order in one place (no `navigationSort` anywhere):
  Dashboard · Customers · group *Operations*: Studio, Billing, Providers · group *System*: Users (admins), Roles, Activity log.
- Plugins: `FilamentShieldPlugin`, `FilamentEChartsPlugin`, `ActivitylogPlugin` (labels: admin audit), `EnvironmentIndicatorPlugin` (LOCAL sky / PRODUCTION red).
- Shield: enable page and widget permissions (`Reports`, `PixelforgeUsage`, `AnthropicUsage`, `Pricing`, every widget) so custom pages and widgets are not visible to any `web` role. Seeded roles: `super_admin` (Gate::before), `operator`, `auditor` (read-only, MFA-exempt).
- `boot()`: Shield super-admin `Gate::before`; listeners record admin writes to the activity log (Buxy's `RecordAdminWrites` pattern).
- Timezone shown: UTC (billing days are UTC); dates show "UTC".
- **No admin page polls the provider on load.** Admin page loads may trigger the opportunistic sweeper (D-036, throttled with `Cache::lock`); that is the only side effect of viewing.

## Definitions (single source for dashboard, Reports, A02/A03/A09/A10)
| Metric | Definition |
|---|---|
| Spend (customer spend) | Σ `usage_records.charge_nanos` where `status = settled`, grouped by `billing_date`. Purchases are prepaid cash, never revenue, and never mixed in. |
| Provider cost | Σ `provider_cost_nanos`, settled only. |
| Profit | spend − cost. |
| Margin | `profit * 10000 / spend` as integer bps; shown `-` when spend = 0 (never 20.0% on an empty day). Invariant on seeded data: 2000 bps (20.0%) on every non-empty day. |
| Takes | Takes created in range (all statuses). |
| Generations (charged) | Count of settled Pixelforge usage records. Failed takes are a separate column ("Failed"); failed takes are free. |
| Failure rate | failed ÷ terminal (succeeded + failed) takes created in range. |
| Duration | `completed_at − submitted_at`; median over succeeded takes in range. |
| Credits sold | Σ ledger `purchase`, in range. |
| Outstanding credits | Σ `customers.balance_nanos` (credits owned, liability). **Held** (Σ `held_nanos`) and **Available** (balance − held, D-024) are separate columns/stats. |
| Stuck (D-053, single source; 2-03 and spec 4 point here) | `queued`/`processing` take with `last_event_at < now − 15 min` (no webhook or reconcile update), or `pending` take with `created_at` older than 35 min (30 min retry window from `created_at` plus grace). Legitimate pending retries inside the window are not stuck. |
| Over-estimate | Derived: `charge_nanos > max_charge_nanos` on a settled usage record (no new column). |
| Orphan success | Webhook outcome `orphan_success` (D-025): provider finished work after we failed the take free (`submit_timeout`); cost absorbed. |
| AI budget used today | Σ settled Anthropic `provider_cost_nanos` today (UTC) vs `AI_DAILY_BUDGET_USD`. |
| Active holds | Σ `customers.held_nanos` (with the count of open usage records). |
| Credits liability | Σ `customers.balance_nanos` (held money is still the customer's). |

All aggregates are SQL `SUM` on bigint; Postgres returns `numeric`, which PDO gives as a string, so every sum is cast through `Nanodollars::fromInt(int|string)` (added in 3-03 for SQL sums; never float).

## Structure
```
app/Filament/Admin/
├── Pages/Dashboard.php
├── Widgets/{MoneyStatsOverview, RevenueCostProfitChart, ProviderCostSplitChart, NeedsAttentionTable}.php
├── Resources/Customers/{CustomerResource, Tables/, Schemas/, Actions/{AdjustCreditsAction, RestrictCustomerAction}, Pages/, RelationManagers/}
├── Resources/Users/…            (admins)
└── Clusters/
    ├── Studio/{StudioCluster, Resources/{Takes, Projects, Suggestions}/…}
    ├── Billing/{BillingCluster, Pages/Reports, Widgets/LedgerInvariantStats, Resources/{LedgerEntries, Purchases, Invoices}/…}
    └── Providers/{ProvidersCluster, Pages/{PixelforgeUsage, AnthropicUsage, Pricing}, Resources/WebhookEvents/…}
```
Clusters use `SubNavigationPosition::Top`, Heroicons, labels from `lang/en/console.php`, badges (Studio: in-progress count; Providers: mismatches read from the cached usage-page result).

## Pages and widgets (A02–A04)
- **Dashboard**: page filters (Today / 30 days / Month) shared by widgets; `MoneyStatsOverview` (spend, provider cost, profit, margin, takes created, generations charged, failure rate, running now, AI budget used today vs `AI_DAILY_BUDGET_USD`, active holds, credits liability, an "Updated hh:mm UTC" label and a **Refresh** button; no polling); `RevenueCostProfitChart` (title "Spend, cost and profit"; ECharts line by `billing_date`); `ProviderCostSplitChart` (ECharts donut); `NeedsAttentionTable` with columns Type, Customer, Reference, Since. All `$pollingInterval = null`. Same range and demo dataset as A03/A10 (D-037).
- **Needs attention** (D-054) is a derived view over DB state, never the provider API. One entry per type: `stuck` (Definitions), `over_estimate`, `unknown_take` (webhook events), `orphan_success`, `invalid_snapshot`, `cost_mismatch`, `reconcile_exhausted`, `internal_failure` (401/409), `ledger_drift` (from `LedgerInvariantStats`), `margin_invariant` (a day's margin <> 2000 bps), `day_mismatch` (last cached Pixelforge usage result, no call). Durable flags are `takes.attention_reason` (varchar null) and `usage_records.flags`, written by specs 3-02/3-03/5; this spec only reads them. The page is also a sweeper trigger (spec 5).
- **Billing › Reports** (D-035): toggle **By day / By customer** plus a **customer filter** (so day × customer is visible on screen), provider filter, default range last 30 days, empty state "No settled usage in this range". Columns: Spend, Provider cost, Profit, Margin, Takes, Generations (charged), Failed, AI suggestions (count), Pixelforge / Anthropic cost split, share of spend, a **reconciled** badge (day matches the cached `/v1/usage` result), and "Promo/adjustment credits consumed" (charges paid from `promo_grant`/`adjustment`), with a totals row. SQL aggregates on `usage_records` (settled only) via one `GROUP BY` with an optional `customer_id` where clause. **CSV export** (permission `export_reports`) always groups by **day × customer**; columns: `billing_date, customer_id, customer_name, provider, generations_charged, spend, cost, profit, margin_bps, spend_nanos, cost_nanos, profit_nanos, promo_consumed, promo_consumed_nanos`. Money columns (money page, D-042) are exact decimal strings (e.g. `0.074037500`) plus integer nanos; the customer name is prefixed with `'` when it starts with `=`, `+`, `-` or `@`; the response is streamed.
  The **reconciled** badge reads the last cached Pixelforge usage check for the day (same rules as A04, no provider call). **Promo/adjustment credits consumed** follows D-084: per customer, in ledger order, promo grants and positive adjustments are spent before purchases.
- **Billing › Ledger entries** has `LedgerInvariantStats` as header widget. One query: customers where `balance_nanos <> coalesce(Σ ledger amount, 0)` and customers where `held_nanos <> coalesce(Σ max_charge_nanos of open usage records, 0)`. Shows a green check or "N customers drifting" with a link to the filtered list. Tested with a deliberately corrupted row.
- **Providers › Pixelforge usage** (D-034): range ≤ 92 days; `GET /v1/usage` (cached 5 min) vs Σ settled Pixelforge usage by `billing_date`; compares **cost and token sums** (to catch offsetting errors); provider cost per day with the 9 decimals the API returns; total row with the "N / M days match" summary. Today (UTC) shows **"N in flight"** instead of Mismatch while takes are non-terminal. The mismatch drill-down (**Find takes**, one button name everywhere) pages `PixelforgeClient::listGenerations(status, cursor)` (`GET /v1/generations`, succeeded, cursor-paginated), keeps those with `completed_at` on the mismatching day and diffs against `takes.pixelforge_id` into four groups: **theirs-not-ours** (also covers takes created outside the app), **ours-not-settled**, **amount differs**, and **Orphan success (cost absorbed)** (D-058: `/v1/usage` includes the orphan's cost but our settled sum does not; each row links to the take and is never a plain mismatch). Each row has **Reconcile now** (same action as A06; runs the reconcile job for that take, which settles through the normal path; orphan rows have none). Result of each usage check is cached for the dashboard row.
  Both pages are built from native Filament components only (#163): filters as a schema form (date pickers, toggle buttons, a searchable customer select), Reports totals as a stats widget, the rows in a Filament table with total summaries, and Find takes as a row action whose result is a nested table with **Reconcile now** per open take (#136).
- **Providers › Webhook events**: backed by `webhook_events` (3-02 columns: `event_id`, `type`, `generation_id`, `payload`, `received_at`; one row per `event_id`) joined to `webhook_deliveries` (3-02 columns: `event_id`, `webhook_event_id`, `take_id`, `type`, `attempt`, `outcome`, `received_at`; D-027). The outcome lives only on `webhook_deliveries.outcome`; `webhook_events` has no outcome column. Column names are exactly those of 3-02 (not `pixelforge_generation_id` or `delivery_attempt`). Header stats: events 24 h = count of events; duplicates = deliveries with outcome `duplicate`; `ignored_stale`; `unknown_take`; `orphan_success`. The outcome filter lists the `WebhookOutcome` enum (3-02; an invalid signature writes nothing, D-056). Tables are migrated in 3-02; spec 5 only adds the handler.
- **Providers › Anthropic usage**: our usage records by day/model. **Pricing**: live `/v1/pricing` vs estimator config. Both are low priority (see Issues).

## Resources (A09–A11 are the source of truth for columns, filters, actions, widgets)
All read-only except: Customers (*Adjust credits*, *Restrict/Unrestrict*), Takes (*Reconcile now*), Users (roles, super_admin only). Every action: Shield permission, confirmation modal, required reason when money or access changes, activity log entry. Adjust credits writes a ledger `adjustment` row (never edits balance directly) under the customer lock.
Queries use `withCount`/`withSum`/joins on bigint columns; no N+1 (tested with `assertQueryCount` style helper on list pages).

- **Take view (A06)**: money (estimate min/max cost and charge, actual cost, charge), **Ledger panel** (the `charge` ledger entry, plus the usage record: status, held `max_charge_nanos` while open, billing day; holds are state, not ledger rows, D-024), **Timeline** (created, submitted, started, completed, from timestamps), failure code/message when failed, full idempotency key, parent take / suggestion link, webhook deliveries (from `webhook_deliveries`, with `orphan_success` flagged). Cluster sub-navigation sits in the page header, not under the take title.
- **Customers**: columns include Balance, Held, Available, Lifetime spend, Last active. `last_active_at` is written at most every 5 minutes (compared in middleware before writing; never on the settle/hold lock path).
- **Data from spec 3** (D-055, D-052): `usage_records.min_cost_nanos`/`min_charge_nanos` (estimate range), integer `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens` (no jsonb parsing; A04 compares all four), `usage_records.flags`, `takes.attention_reason`, `takes.last_event_at`. Money pages show exact amounts (D-042).

## Tests
- Panel access by guard and role; MFA closure per role (operator required, auditor exempt); auditor sees no write actions; custom pages and widgets are hidden without their Shield permission; operator can adjust (ledger `adjustment` + activity log).
- Reports totals equal Σ usage records in all groupings (day, customer, day × customer, CSV); margin = 2000 bps on every non-empty seeded day and `-` on an empty day; CSV formula-injection prefix and exact decimal strings.
- Pixelforge usage page with faked `/v1/usage` and `/v1/generations`: match, mismatch with each of the four groups (including orphan success), today "in flight".
- Stuck rule: a pending retry inside 35 min is not flagged; a processing take silent for 16 min is. Each Needs attention type renders from its flag.
- `LedgerInvariantStats` flags a deliberately corrupted balance and held row.
- Needs attention reads no provider API (HTTP fake asserts nothing sent).
- `filacheck` clean.

## Issues
Order is by evaluator value; Pricing and Anthropic usage are last.
1. **Spike (1 h, D-061)**: confirm MFA closure per role, Shield page/widget permissions, and that `elemind/filament-echarts` installs on Filament 5; if it fails, fall back to core `ChartWidget` (line, doughnut). Record the outcome in DECISIONS.md.
2. Install Filament + Shield (D-048), `User implements FilamentUser`, MFA columns; panel provider + navigation + plugins + MFA/auditor + Shield roles seeding + the **seeded auditor** and the `/admin/login` demo-access render hook (shown only when `takes.demo.enabled`; 3-01 and spec 10 rely on them) + activity log wiring.
3. Billing cluster: Reports page (by day / by customer / customer filter / CSV), Ledger entries + `LedgerInvariantStats`.
4. Providers: Pixelforge usage + Find takes drill-down (`listGenerations`), Webhook events.
5. Studio cluster: Takes table and take infolist (A06), Projects. The Suggestions resource is built once, in spec 8 issue 5.
6. Dashboard widgets (stats, ECharts, Needs attention).
7. Customers resource (+ adjust, restrict, relation managers).
8. Users (admins) resource, Purchases and Invoices resources (need spec 6).
9. Anthropic usage and Pricing pages (deferred if time is short). This issue is the single owner of the Anthropic usage page; spec 8 builds none of it (spec 8 issue 5 owns only the Suggestions resource and its Edit in composer action).
