# Architecture: Actions, Queries, DTOs, Enums, Exceptions

Imperative rules for every session writing PHP in this repository. All business logic lives in **Actions** (effects) or **Query Objects** (reads); data travels in **DTOs**. Controllers, models, jobs and Filament pages stay slim. Ported from the house template and adapted to Takes (spec 1-01 enforces the mechanical parts with Pest arch tests in `tests/Arch/ArchitectureTest.php`).

The application layer is organised **per domain**:

```
app/
  Actions/{Domain}/       write / side-effect operations
  Queries/{Domain}/       read operations
  DTOs/{Domain}/          immutable input/output value objects
  Enums/{Domain}/         backed enums (single source of every enum-like column)
  Exceptions/{Domain}/    domain exceptions (not HTTP exceptions)
  Support/Money/          Nanodollars value object (money.md)
  Services/Pixelforge/    PixelforgeClient: the only "service" (thin HTTP client, spec 3-04)
  Contracts/{Domain}/     seams only: an interface one spec calls and another spec implements
```

### Seams (`App\Contracts\{Domain}`)

`App\Contracts` holds **only seams**: an interface that lets a spec merge before the spec that implements it (e.g. `Contracts\Takes\CreatesSuggestedTake`, called by spec 8's apply and implemented by spec 4 over `CreateTakeAction`). Rules:

- The interface docblock is the contract (transaction, lock order, idempotency, authorization, exceptions); the implementation must honour all of it.
- The default binding is a null implementation in `App\Support\{Domain}` that refuses with a domain exception (never a silent no-op), bound with `bindIf` in `AppServiceProvider` so the implementing spec rebinds it.
- No business logic in a contract, no contract for code that already exists in the same PR, and never a contract to wrap an Action that could be called directly.
- `App\Services` stays `PixelforgeClient` only (arch test).

Domains in this project: `Auth`, `Projects`, `Takes`, `Billing`, `Webhooks`, `Suggestions`, `Pixelforge`. Add a subdirectory per new domain; never dump unrelated classes in a flat folder.

## Action vs Query Object

- **Action** if the operation **writes** state: creates, updates or deletes rows, opens/settles/voids usage, writes ledger entries, fires events, dispatches jobs, calls an external service that mutates something. If it has a side effect, it is an Action.
- **Query Object** if it only **reads**: paginated lists, counts with filters, a lookup with preloaded relations, aggregates (sums cast through `Nanodollars::fromInt`). Never mutate inside a Query Object.
- "Find or create" (`firstOrCreate`, idempotent replays) is an **Action**.

## Naming and shape (enforced by arch tests)

- **Actions:** `{Verb}{Noun}Action`, `final readonly class`, the only public method is `execute(...)`. Never `handle()` (collides with jobs/listeners) or `__invoke()` (hides the operation in stack traces). Examples: `OpenUsageAction`, `ApplyTakeSnapshotAction`, `HandlePixelforgeWebhookAction`.
- **Query Objects:** `Get{Noun}Query`, `List{Noun}Query`, `Search{Noun}Query`; `final class`; public `execute(...)` (and optionally `builder()` returning the Eloquent builder for Filament tables).
- **DTOs:** `{Concept}DTO`, `final readonly class`, promoted public typed properties, a static `fromArray(array $data): self` **always** with the array shape in PHPDoc (`@param array{id: string, status: string} $data`), `fromRequest(FormRequest $request): self` only for HTTP inputs. No business logic beyond trivial normalisation. Exception: an output-only result DTO that a Query builds from its own typed values and that never travels as an array (e.g. `PixelforgeReconciliationDTO`, `ReconciliationDayDTO`) may omit `fromArray`; say so in its docblock. An Action returning more than one value returns a result DTO, never an associative array.
- **Enums:** backed (`string`), TitleCase cases, under `App\Enums\{Domain}`. Enum-like columns are `varchar` + `CHECK` built from the enum; never native Postgres enums (spec 3-02). Labels for the UI come from lang files, not hardcoded in the enum.
- **Exceptions:** `{Condition}Exception` under `App\Exceptions\{Domain}` (`InsufficientCreditsException`, `PixelforgeRateLimitedException`). Controllers translate them to HTTP; actions never return HTTP responses.

## No Service classes, no Repositories

1. One use case = one Action or Query Object. No `TakeService`, `BillingService`, `WebhookService`.
2. Eloquent is the repository. No `Repository` classes.
3. The single exception is `App\Services\Pixelforge\PixelforgeClient` (spec 3-04): thin, typed, no retries inside (jobs own the retry policy), used only from Actions, Queries, Jobs, the Inertia shared-props middleware and Filament pages/widgets, never from controllers, models or resources.

## Controllers

A healthy controller method is about 10 lines: authorize, validate (FormRequest), build the DTO, call the Action/Query, return `Inertia::render(...)` with Resources or a redirect. **None** of the following belongs in a controller:

- Eloquent queries beyond `$request->user()` or a trivial relation; `DB::` or query builders (arch test).
- `DB::transaction(...)`: the transaction belongs in the Action.
- Branching that decides which operation to run; money math; hashing; token generation.
- Calls to external services, mail, queues.
- Authorization rules: they live in Policies (`$this->authorize(...)` or `FormRequest::authorize()`); denials use `Response::deny()` with a `DenialReason` code (D-015, D-050).

Translating a domain exception into an HTTP response **is** the controller's job.

## Models

- May carry: `casts()`, relations, simple single-`where` scopes, formatting accessors, minimal `booted()` hooks (ulid generation, append-only guards on `LedgerEntry`).
- No `$fillable` and no `$guarded = []`: actions write with explicit `forceFill([...])` (house style, spec 3-02).
- No complex queries (Query Object), no multi-model persistence (Action), no side effects in mutators.
- Models live only in `App\Models` (arch test). Morph map in `AppServiceProvider`; no class names in the database.

## Jobs, events, listeners

- Jobs are thin: load state, call an Action, decide whether to re-dispatch. Retry policy lives in the job; no delay, backoff or release above **900 s** (money.md M-17).
- Provider calls happen outside transactions (money.md M-16); the job applies the result through an Action in a fresh transaction.
- Events that broadcast implement `ShouldDispatchAfterCommit` (webhooks.md W-18).
- An Action may implement `ShouldQueue` only if it is fire-and-forget, heavy, and the UX already says "processing". Its inputs must be serializable (models via `SerializesModels`, DTOs of primitives).

## Auth guards (D-012, D-032)

- Admins: `users` + default `web` guard (Filament, M7). Customers: `customers` + `customer` guard.
- Customer routes: `auth:customer` plus the middleware that calls `Auth::shouldUse('customer')`. One test per feature group hits a route as a customer.
- Policies receive the right user type; never mix `User` and `Customer` in one ability.

## PHP style

- `declare(strict_types=1);` in every PHP file (Pint rule).
- Larastan level 8, no baseline; explicit parameter and return types; array shapes in PHPDoc.
- `env()` only inside `config/` (arch test). No closures in config files.
- No `dd`, `dump`, `ray` (arch test).
- Constructor property promotion; no empty zero-argument constructors.

## Invoking Actions from Filament (M7)

Actions and Queries resolve through the container (`app(...)` or constructor injection).

```php
Action::make('adjustCredits')
    ->requiresConfirmation()
    ->action(fn (Customer $record, array $data) => app(AdjustCreditsAction::class)->execute(
        $record,
        AdjustCreditsDTO::fromArray($data),
    ));
```

Never hand-build logic in a Filament page that an Action already owns; reuse the DTO (add `fromArray` if it only had `fromRequest`).

## The golden rule

> If the logic may ever run outside an HTTP request (Artisan command, queued job, webhook, listener, Filament action), it goes in an Action, or a Query Object if it is a read.
