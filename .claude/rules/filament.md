# Filament rules: admin console (Filament 5)

Applies to: `app/Filament/**`, `app/Providers/Filament/**`, `lang/*/console.php`, and the Actions/Queries the console calls.
Sources: spec 7 (admin console), 2-03 (admin screens), decisions D-012, D-013, D-014, D-017, D-023, D-038, D-042, D-048, D-054, D-058, D-061.
Filament, Shield, the MFA columns and admin roles are installed when **M7** starts (D-048). Until then this file is guidance for planning, and `app/Filament` does not exist.

Each rule has an ID that reviewers cite. Default severity is **important**; rules marked **critical** block the merge.

## Panel

- [ ] **FI-01 One panel, `admin`, on the `web` guard (D-012).** `App\Providers\Filament\AdminPanelProvider`: `id('admin')`, `path('admin')`, built-in `login()`, `passwordReset()`, MFA required through the panel's `multiFactorAuthentication(..., isRequired: fn () => ...)` closure, which returns false only for the auditor role (D-013). No custom login page, no model method for MFA.
- [ ] **FI-02 Panel options.** `spa()`, `sidebarCollapsibleOnDesktop()`, `breadcrumbs(hasNavigationHierarchy: true)`, `globalSearch(false)`, `databaseNotifications()` without polling, `discoverClusters/Pages/Resources/Widgets` under `app/Filament/Admin/*`. Times shown in UTC with the "UTC" suffix.
- [ ] **FI-03 Navigation builder only.** The whole sidebar order is defined once with `->navigation(...)` in the panel provider: Dashboard · Customers · group Operations (Studio, Billing, Providers) · group System (Users, Roles, Activity log). No `$navigationSort`, `$navigationGroup` or `getNavigationSort()` anywhere else.

## Structure

- [ ] **FI-04 Clusters.** `Studio` (Takes, Projects, Suggestions), `Billing` (Reports, Ledger entries, Purchases, Invoices), `Providers` (Pixelforge usage, Anthropic usage, Pricing, Webhook events). Clusters use `SubNavigationPosition::Top`, Heroicons, labels from `lang/en/console.php`.
- [ ] **FI-05 Resource split.** Each resource is a folder:
  ```
  Resources/{Entities}/
    {Entity}Resource.php          wiring only: model, cluster, pages, table(), form()/infolist() delegations
    Tables/{Entities}Table.php    columns, filters, record actions
    Schemas/{Entity}Form.php      form schema (only for editable resources)
    Schemas/{Entity}Infolist.php  view schema
    Actions/{Verb}{Noun}Action.php  Filament actions (thin: call an App\Actions class)
    Pages/                        List/View(/Edit) pages
    RelationManagers/
  ```
  No column, filter or schema definitions inline in the resource class.
- [ ] **FI-06 Read-only by default (D-017).** Resources are list + view only, except: Customers (Adjust credits, Restrict/Unrestrict), Takes (Reconcile now), Users (roles, super_admin only). No create/edit/delete pages for money tables.

## Permissions (critical)

- [ ] **FI-07 Shield on everything.** Every resource, custom page (`Reports`, `PixelforgeUsage`, `AnthropicUsage`, `Pricing`), widget and action is behind a Shield permission; page and widget permissions are enabled so nothing is visible to a `web` role by default. **Critical.**
- [ ] **FI-08 Write actions are audited.** Every write action: Shield permission, confirmation modal, a required reason when money or access changes, an activity-log entry. The write itself goes through an `App\Actions\*` class (architecture.md); money changes go through `RecordLedgerEntryAction` under the customer lock (money.md M-07, M-10). **Critical.**
- [ ] **FI-09 Auditor is read-only.** The auditor role sees no write action (hidden, not just disabled), and is MFA-exempt.

## Data and performance

- [ ] **FI-10 No polling (D-038).** Every widget sets `protected ?string $pollingInterval = null;`, and tables do not poll. Dashboards refresh with an explicit Refresh button and show "Updated hh:mm UTC".
- [ ] **FI-11 No provider API on page load.** Admin pages read the database; only Providers › Pixelforge usage calls `/v1/usage` (cached 5 min) and the drill-down pages `/v1/generations` on an explicit "Find takes" click. Needs attention is a derived DB view (D-054). The only side effect of viewing is the throttled sweeper (D-036).
- [ ] **FI-12 No N+1.** List pages use `withCount`, `withSum` or joins on bigint columns; eager-load relations used by columns. Covered by a query-count test per list page.
- [ ] **FI-13 Aggregates are SQL sums** cast through `Nanodollars::fromInt(int|string)` (money.md M-04); never sum in PHP over a collection of models, never sum formatted values.
- [ ] **FI-14 Exact money in the admin (D-042).** Amounts use `Nanodollars::formatExact()`; Pixelforge cost per day keeps the 9 decimals the API returns. CSV exports carry exact decimal strings plus integer nanos and prefix `'` to names starting with `=`, `+`, `-`, `@`.
- [ ] **FI-15 Definitions come from spec 7.** Spend, provider cost, profit, margin (integer bps, `-` when spend is 0), stuck (D-053), over-estimate and orphan success are computed exactly as the spec 7 Definitions table says; one Query Object per metric, reused by the dashboard and Reports.

## Code quality

- [ ] **FI-16 Filament 5 APIs only.** Schemas (`Filament\Schemas\Schema`), `Filament\Actions\Action` for every action, `recordActions()` / `toolbarActions()`; check the installed version and Boost `search-docs` before using an API. No Filament 3/4 namespaces.
- [ ] **FI-17 Labels from lang.** Every label, heading, column, filter, action and notification text comes from `lang/en/console.php` (or Filament's own translations); no hardcoded English in PHP.
- [ ] **FI-18 Enums carry `HasLabel` / `HasColor`** for badges; no ad-hoc `match` on status strings in tables.
- [ ] **FI-19 `composer filacheck` is clean.**
- [ ] **FI-20 Plugins.** Shield, `elemind/filament-echarts` (spiked first; fall back to core chart widgets with the same layout if incompatible, D-061), activity log, environment indicator. Filament Blueprint is local-only and never committed (D-003).
