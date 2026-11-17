# 3-04 · Pixelforge client

Status: **Approved** · Milestone: **M3 Domain foundation** · Depends on: 3-02 · Unblocks: 4, 5, 7 · Parallel: no (lead)
Reviews: product ☑ · technical ☑ · Decisions: D-025, D-033, D-034, D-049, D-056, D-057, D-062

## Shape
`App\Services\Pixelforge\PixelforgeClient` (the only "service", allowed exception like Tezora's provider clients). Thin, typed, no retries inside (jobs own retry policy).

| Method | Endpoint | Returns |
|---|---|---|
| `createGeneration(CreateGenerationDTO $req, string $idempotencyKey): GenerationSnapshotDTO` | `POST /v1/generations` | snapshot + `replayed: bool` (`Idempotent-Replayed`) |
| `getGeneration(string $id): GenerationSnapshotDTO` | `GET /v1/generations/{id}` | snapshot |
| `listGenerations(?string $status, ?string $cursor, int $limit = 100): GenerationPageDTO` | `GET /v1/generations` | snapshots + `nextCursor` (reconciliation, D-034) |
| `getPricing(): PricingDTO` | `GET /v1/pricing` (cached 1 h, config fallback) | rates + token tables |
| `getUsage(CarbonImmutable $from, CarbonImmutable $to): UsageReportDTO` | `GET /v1/usage` | per-day rows with `Nanodollars` |

**`getUsage` contract (for spec 7):** days are UTC, inclusive, scoped to the **current key** only, maximum 92 days (the client chunks longer ranges and merges; `422 date_range_too_long` is a bug). Provider cost per day must equal Σ settled `usage_records` (`provider = pixelforge`) by `billing_date` (an orphan success makes Pixelforge's side larger; spec 7 labels it); days without usage come back as zeros.

HTTP: `Http::baseUrl(config)->withToken()->acceptJson()->timeout(10)->connectTimeout(3)`. Logs request id, status, rate-limit headers; never the key. `Retry-After` is parsed as seconds or HTTP-date.

## DTOs (`App\DTOs\Pixelforge`, `final readonly`, `fromArray()` with array shapes)
`CreateGenerationDTO` (prompt, size, quality, referenceImages, metadata `{app_take_id, app_customer_id}`), `GenerationSnapshotDTO` (id, status, usage?, rates, imageUrl?, imageUrlExpiresAt?, error?, createdAt, startedAt?, completedAt?, metadata), `GenerationPageDTO`, `UsageTokensDTO`, `RatesDTO` (nanodollars per 1M, parsed from the generation's `rates` object: decimal strings with the same keys as `/v1/pricing`), `PricingDTO`, `UsageReportDTO`.
Decimal strings are parsed with `Nanodollars`; timestamps with `CarbonImmutable` UTC. `rates` is present on the generation object and therefore in every webhook payload (`data.object`); fixtures are copied from the docs and one real response. If `rates` is ever missing, settlement fetches the generation first.

## Errors → exceptions (`App\Exceptions\Pixelforge`), aligned with D-025 / D-049
Customer-facing copy for every code is in 2-01 only (D-057); this table names codes, not strings.
| HTTP / code | Exception | Caller behavior (spec 4) |
|---|---|---|
| 429 `rate_limit_exceeded`, `concurrency_limit_exceeded`; 503 `overloaded` | `PixelforgeBusy(retryAfter, code)` | **definite non-creation**: take stays `pending` with `last_submit_error_kind = capacity`; release job after Retry-After (+ jitter), **every release delay capped at 900 s**; after 30 min of this (from `takes.created_at`) fail free `provider_busy` |
| connection error / timeout / other 5xx | `PixelforgeUnavailable` | **ambiguous**: `last_submit_error_kind = ambiguous`, retry with the same idempotency key (5 s, backing off, cap 900 s) for up to 30 min from `takes.created_at`, then fail free `submit_timeout` (orphan possible) |
| 429 `daily_limit_exceeded` | `PixelforgeRejected(code)` | fail free now, `daily_limit`; no retry |
| 400 `content_policy_violation` | `PixelforgeRejected(code)` | fail free now, `content_policy` |
| 422 `validation_failed` | `PixelforgeRejected(code)` | fail free now, `validation` |
| 401 `invalid_api_key`, 409 `idempotency_key_reused`, 404 | `PixelforgeRejected(code)` + **critical log** | fail free **immediately**, `internal` (D-062, D-025: definite rejection); `attention_reason = internal_failure` flags a configuration/bug problem |

**Global concurrency gate (in the submit job, not the client):** Pixelforge allows 3 in-flight generations and 60 requests/min per account. Before calling `createGeneration` the job takes a short DB-based gate (`Cache::lock` on the database store) and counts takes with `pixelforge_id` non-terminal plus those currently submitting; at 3 it releases the job (jitter 3-8 s) without calling the API. `PixelforgeBusy` remains the fallback. This avoids herds of rejected calls burning the per-minute budget.

**Submit invariants:** `takes.idempotency_key` (spec 3-02, D-033) is persisted before the first request and sent unchanged on every attempt. A replayed create returns `replayed = true` and no extra charge; tested with a simulated timeout followed by a replay.

## Tests
`Http::fake()` fixtures copied from the docs for every row above; DTO parsing (usage null until success, rates strings, nine-decimal usage); `Retry-After` as seconds and as HTTP-date; list pagination by cursor; `getUsage` chunking over 92 days; replay after timeout.

## Issues
1. Client + DTOs + exceptions + fixtures tests (incl. `listGenerations`, usage chunking).
2. `config/pixelforge.php` (base URL, key, secret, tolerance, pricing fallback, 30-min window (anchor `takes.created_at`), 900 s max delay, concurrency cap). Webhook signature failures return 401 and write nothing (D-056, spec 5).
3. Submit gate helper (global in-flight cap) used by spec 4's submit job.
4. `PixelforgeClient::isConfigured()` (key present) shared as Inertia prop `pixelforge.configured`; `createGeneration` is never called when false. The banner UI and composer disabling are spec 4 issue 8 (this spec builds no UI). Pest test: with `PIXELFORGE_API_KEY` empty the prop is false and `createGeneration` is never called; with the key set the prop is true.
