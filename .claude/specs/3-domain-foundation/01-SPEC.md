# 3-01 · Identities and authorization

Status: **Approved** · Milestone: **M3 Domain foundation** · Depends on: 1 · Unblocks: 4, 6, 7, 8 · Parallel: no (lead)
Reviews: product ☑ · technical ☑ · Wireframes: P02, A08

## Decisions
D-012 (users = admins, customers = own table/guard), D-013 (MFA + auditor), D-015 (permissions vs state), D-019/D-026 (welcome credit), D-030 (registration without email verification), D-032 (`auth:customer` + `Auth::shouldUse`), D-044/D-045 (demo access), D-048 (Filament/Shield at M7), D-050 (denial reasons and caps), D-053.

## Guards and models
| Guard | Model / table | Used by | Auth UI |
|---|---|---|---|
| `web` (default) | `App\Models\User` / `users` (Laravel default, unchanged in M3) | Filament console `/admin` (installed at M7, D-048) | Filament login, MFA, password reset: **spec 7 issue 2** |
| `customer` | `App\Models\Customer` / `customers` | Public app | Starter-kit pages (Fortify) configured with `guard = customer`, password broker `customers` |

- `config/auth.php`: guards `web` and `customer`, providers `users` and `customers`, brokers `users` and `customers`. **Separate reset tables**: `passwords.users.table = password_reset_tokens`, `passwords.customers.table = customer_password_reset_tokens`. Test: the same email on both tables, resetting one never touches the other.
- Fortify: `'guard' => 'customer'`, `'passwords' => 'customers'`. **No email verification** (D-030): `Customer` does not implement `MustVerifyEmail`; `email_verified_at` stays nullable and unused. Registration creates the customer, assigns role `customer` and logs in. The **welcome grant** is owned by spec 4 (`GrantWelcomeCreditAction` + the Fortify hook; its unique index is in 3-03); this spec only provides the customer and the guard.
- **Default guard stays `web` (D-012); customers resolve through D-032.** Every customer route sits in one group: `auth:customer` + a middleware that calls `Auth::shouldUse('customer')`, so `$request->user()`, `Gate::inspect()`, FormRequest `authorize()`, policies and Inertia shared props see the customer. Outside HTTP (jobs, actions) code passes the `Customer` explicitly (`Gate::forUser($customer)`), never `Auth::user()`.
- `Customer` uses `HasRoles` with explicit `protected $guard_name = 'customer'`, `Notifiable`. The `User` Filament interfaces (`FilamentUser`, app authentication) arrive with Filament in spec 7 (D-048).
- Customers are never hard-deleted (money tables use `restrict` FKs, spec 3-02).
- Broadcasting: private channel `App.Models.Customer.{id}` declared with `Broadcast::channel(..., fn, ['guards' => ['customer']])`; the route group uses `web` + `auth:customer`; setup in `withBroadcasting()` (`bootstrap/app.php`). Tests on `/broadcasting/auth`: own channel 200, another customer's 403, admin session 401 (unauthenticated on the customer guard).

## Roles and permissions (spatie/laravel-permission)
**Customer guard only in M3 (D-048).** Filament, Shield, the MFA columns, admin roles (`super_admin`, `operator`, `auditor`), `AdminPermission` and the MFA/auditor-exemption/Shield-scoping work are installed and tested in **spec 7 issue 2**. The spatie tables are created here and used by both guards.

**customer guard**: enum `App\Enums\Auth\CustomerPermission`: `takes.create`, `ai.suggest`, `credits.purchase`, `projects.manage`.

| Role | takes.create | ai.suggest | credits.purchase | projects.manage |
|---|---|---|---|---|
| `customer` (default on registration) | ✓ | ✓ | ✓ | ✓ |
| `restricted` role (set by an operator) | ✗ | ✗ | ✓ | ✓ |

An operator switches a customer with the *Restrict / Unrestrict* action (spec 7, audited, permission `restrict_customer`). The role is named `restricted`; the denial reason code is `account_restricted`.

## Policies: permission + state, with reason codes
Reason codes are one enum, `App\Enums\Auth\DenialReason` (D-050): `insufficient_credits`, `account_restricted`, `daily_take_cap`, `demo_quota_reached`, `rate_limited`, `ai_paused`, `ai_daily_limit`, `demo_account_locked` (D-072). Specs 4, 8 and 9 reuse it; customer copy for each lives in 2-01 only (D-057), loaded from `lang/en/denials.php` keyed by the enum value; this spec and the others hold no literal strings.
- `TakePolicy::create(Customer $c, Estimate $e): Response`, in this order:
  1. `! $c->can('takes.create')` → deny `account_restricted` (customer copy: the 2-01 string for `account_restricted`, which interpolates `config('takes.support_email')`; no literal here, D-057).
  2. Customer's takes created on the current UTC day ≥ `config('takes.daily_cap_per_customer')` (default 40) → `daily_take_cap`.
  3. Global takes created today (all customers, UTC) ≥ `config('takes.daily_cap_global')` (default 300) → `demo_quota_reached`. **Meaning (single statement, copy in 2-01):** today's shared capacity is used up; it is not a per-account quota and applies to any customer, registered or demo.
  4. `$c->available() < $e->maxCharge` → `insufficient_credits` (also covers negative available, 3-03 §6).
  5. allow.
  Config keys, defaults and env names are owned by this spec (**`config/takes.php`**, below); specs 4 and 8 only read them and spec 9 only sets env values. `rate_limited` is returned by the route throttle, not the policy.
- `SuggestionPolicy::create(Customer $c)`: `ai.suggest` missing → `account_restricted`; `config('takes.ai.enabled')` false or global daily AI budget reached (`takes.ai.daily_budget_nanos`, $1/day) → `ai_paused`; customer already has `takes.ai.daily_limit_per_customer` (30) suggestions on the current UTC day → `ai_daily_limit`; `available < AI max charge` → `insufficient_credits`; the 6/min/customer throttle is `rate_limited`. Global budget = Σ `usage_records.provider_cost_nanos` where `provider = anthropic` and `billing_date` = today (open usages count their `max_cost_nanos`).
- `ProjectPolicy`, `TakePolicy::view` etc.: ownership (`customer_id`), plus `projects.manage`.
- FormRequests call `Gate::inspect()` in `authorize()`; a denied response is rendered back to Inertia with its code. A restricted customer also sees a banner in the composer (wireframe P05).
- **The action re-checks under lock** (spec 3-03 §6). The policy is UX; the lock is the guarantee.
- Never change roles/permissions when the balance changes (D-015).

## `config/takes.php` (owned here; specs 4 and 8 only use it)
| Key | Env | Default |
|---|---|---|
| `takes.support_email` | `TAKES_SUPPORT_EMAIL` | `support@example.com` (required in prod) |
| `takes.daily_cap_per_customer` | `TAKES_DAILY_CAP_PER_CUSTOMER` | 40 |
| `takes.daily_cap_global` | `TAKES_DAILY_CAP_GLOBAL` | 300 |
| `takes.welcome_credit_nanos` | `WELCOME_CREDIT_USD` (USD string) | 1_000_000_000 (`1.00`; read by spec 4's `GrantWelcomeCreditAction`) |
| `takes.ai.enabled` | `AI_SUGGESTIONS_ENABLED` | true |
| `takes.ai.daily_budget_nanos` | `AI_DAILY_BUDGET_USD` (USD string) | 1_000_000_000 (`1.00`, $1/day) |
| `takes.ai.daily_limit_per_customer` | `AI_DAILY_LIMIT_PER_CUSTOMER` | 30 |
| `takes.ai.throttle_per_minute` | `TAKES_AI_THROTTLE_PER_MINUTE` | 6 |
| `takes.demo.enabled` | `DEMO_LOGIN_ENABLED` | false (true in production, spec 9) |
| `takes.demo.throttle_per_ip_per_hour` | `DEMO_LOGIN_THROTTLE_PER_IP` | 5 (read by spec 4's `throttle:demo`) |
| `takes.demo.ttl_hours` | none | 24 (ephemeral demo expiry, spec 4) |
`takes.purchase_packs_usd` (`[5, 10, 25]`, no env) is defined once in 3-03, which owns `PurchaseCreditsAction`; specs 4 and 6 only read it. **Value format:** the env names above are the only ones. The two USD env values are decimal **strings**, parsed once while building `config/takes.php` through `Nanodollars::fromDecimalString` (never `(float)`), so every `takes.*` config value is an **integer** (nanodollars) or bool; nothing downstream sees a float or a USD string. Test: defaults load, env overrides apply, `AI_DAILY_BUDGET_USD=0.5` gives 500_000_000.

## What the frontend receives
Shared Inertia prop `auth.customer` (id, name, email, `available`, `on_hold` as formatted strings; `available = balance − held`, D-024) and per-page `can` objects, e.g. `can.createTake = { allowed: false, reason: 'insufficient_credits' }`, recomputed with the current estimate. Never provider cost, tokens, provider names or margin (D-023).

## Demo accounts (`DEMO_LOGIN_ENABLED=true` only; D-030, D-045)
**The panel (P02, same list in 2-02 and spec 10):** one **Try a demo account** button plus a short list of seeded logins.
- **Try a demo account** creates a **fresh ephemeral customer** with the welcome credit (via spec 4's `GrantWelcomeCreditAction`). **Spec 4 owns `POST /demo`, the button and the per-IP throttle** (reading `takes.demo.throttle_per_ip_per_hour`); this spec only provides the config keys and the P02 panel shell, with the button hidden until spec 4, so M3 is complete at Gate B. Evaluators therefore never share balance, takes or the Pixelforge quota (the ephemeral customer uses the `is_demo` / `demo_expires_at` columns of 3-02). Real registration (no verification) is the primary path.
- **Showcase customer** (read-only, login credentials in the README, spec 9/10 rely on it): a real-history account. In production its history comes from real smoke-test calls, never seeded fakes. Anyone with the README credentials may log in; it is not for creating takes (its balance is topped up by the operator only). **D-072:** it is seeded with role `restricted`; spec 9's smoke test runs `php artisan takes:showcase --unlock` (role `customer`), creates the history, then `takes:showcase --lock`.
- **Locked credentials (D-072):** no seeded demo login may change its name, email or password (`CustomerPolicy::updateAccount` → `demo_account_locked`); the seeder re-asserts `takes.demo.password` on every run.
- Seeded fixed-state accounts, **local, CI and screenshots only** (D-037, D-045): *Demo customer* (full-history dataset, $10.00), *Low balance* ($0.01), *New customer* ($0.00), always through ledger entries. **In production** the seeder creates users, customers and credit only: *Demo customer* has a $10 purchase in the ledger and no takes; takes and usage records are never seeded there.
- Admin demo access (seeded *auditor* + `/admin/login` render hook) belongs to spec 7 issue 2 (Filament).
- Flag off ⇒ no demo routes.

## Tests
- Guard isolation: customer routes reject a `web` session (admin-side check moves to spec 7).
- D-032: for each feature group, one test logs in a customer and hits a route (policy, `$request->user()`, shared props resolve the customer).
- Each `DenialReason` the policies return (incl. both caps); restricted customer; broker isolation; broadcast auth (own 200, other customer 403, admin session 401); shared demo logins cannot change name, email or password (`demo_account_locked`); `takes:showcase --unlock|--lock`.
- Registration creates the customer and logs in with no verification step (welcome grant tested in spec 4).
- Concurrency: two simultaneous creates with balance for one ⇒ exactly one succeeds (spec 3-03).

## Issues
1. Customer model (migration and columns: 3-02 issue 1), guard, brokers (separate token tables), Fortify on `customer` guard, registration creating the customer (welcome grant: spec 4), `Auth::shouldUse` middleware + per-feature guard test.
2. spatie roles/permissions for the customer guard (seeder, `CustomerPermission`), `DenialReason` enum, `config/takes.php` with the keys above (+ test). No Filament/Shield in M3 (D-048).
3. Policies with reason codes + shared `can` props; broadcast channel with guard (channel authorization is owned here, spec 5 only uses it).
4. Demo accounts: showcase customer, seeded fixed-state accounts (non-production dataset rules above), P02 panel shell with seeded logins, and the `takes.demo.*` config keys. The **Try a demo account** button, `POST /demo` and the throttle are built in spec 4 issue 1; the button stays hidden until then. No test here depends on spec 4.
