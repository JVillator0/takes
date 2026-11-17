<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\DTOs\Pixelforge\CreateGenerationDTO;
use App\DTOs\Pixelforge\GenerationPageDTO;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\DTOs\Pixelforge\PricingDTO;
use App\DTOs\Pixelforge\UsageReportDTO;
use App\Exceptions\Pixelforge\PixelforgeBusy;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Exceptions\Pixelforge\PixelforgeRejected;
use App\Exceptions\Pixelforge\PixelforgeUnavailable;
use App\Support\Pixelforge\GenerationId;
use App\Support\Pixelforge\RetryAfter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The only door to Pixelforge (spec 3-04). Thin and typed: one method per
 * endpoint, DTOs in and out, failures mapped to three exceptions. It never
 * retries; the caller (a job) owns the retry policy.
 *
 * Logs method, endpoint, status, request id and rate-limit headers for
 * every call. Never the API key, never request or response bodies.
 */
final class PixelforgeClient
{
    public const string PRICING_CACHE_KEY = 'pixelforge:pricing';

    /** Used when a 429/503 has no readable Retry-After (the docs' concurrency value). */
    public const int DEFAULT_RETRY_AFTER_SECONDS = 5;

    private const array LIST_STATUSES = ['queued', 'processing', 'succeeded', 'failed'];

    /** True when an API key is set. Shared as the `pixelforge.configured` prop. */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * POST /v1/generations. The same idempotency key must be sent on every
     * attempt for one take (D-033): a replay returns the existing generation
     * with `replayed = true` and is never billed twice.
     *
     * @throws PixelforgeBusy|PixelforgeUnavailable|PixelforgeRejected|PixelforgeNotConfigured
     */
    public function createGeneration(CreateGenerationDTO $request, string $idempotencyKey): GenerationSnapshotDTO
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('An idempotency key must have 1 to 255 characters.');
        }

        $response = $this->send(
            'POST',
            'generations',
            fn (PendingRequest $http): Response => $http
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('generations', $request->toArray()),
        );

        $replayed = strtolower($response->header('Idempotent-Replayed')) === 'true';

        return $this->parse($response, fn (array $data): GenerationSnapshotDTO => GenerationSnapshotDTO::fromArray($data, $replayed));
    }

    /**
     * GET /v1/generations/{id}.
     *
     * @throws PixelforgeBusy|PixelforgeUnavailable|PixelforgeRejected|PixelforgeNotConfigured
     */
    public function getGeneration(string $id): GenerationSnapshotDTO
    {
        if (! GenerationId::isValid($id)) {
            throw new InvalidArgumentException("Invalid Pixelforge generation id [{$id}].");
        }

        $response = $this->send('GET', 'generations/{id}', fn (PendingRequest $http): Response => $http->get("generations/{$id}"));

        return $this->parse($response, GenerationSnapshotDTO::fromArray(...));
    }

    /**
     * GET /v1/generations, newest first. Follow `nextCursor` until it is null
     * (reconciliation drill-down, D-034).
     *
     * @param  string|null  $status  queued, processing, succeeded or failed; null for all
     *
     * @throws PixelforgeBusy|PixelforgeUnavailable|PixelforgeRejected|PixelforgeNotConfigured
     */
    public function listGenerations(?string $status = null, ?string $cursor = null, int $limit = 100): GenerationPageDTO
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('The page limit must be between 1 and 100.');
        }

        if ($status !== null && ! in_array($status, self::LIST_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid Pixelforge status filter [{$status}].");
        }

        $query = array_filter(
            ['limit' => $limit, 'status' => $status, 'cursor' => $cursor],
            fn (int|string|null $value): bool => $value !== null && $value !== '',
        );

        $response = $this->send('GET', 'generations', fn (PendingRequest $http): Response => $http->get('generations', $query));

        return $this->parse($response, GenerationPageDTO::fromArray(...));
    }

    /**
     * GET /v1/pricing (public), cached for an hour on the default cache
     * store. If Pixelforge cannot answer, the config fallback is served and
     * cached briefly, so an estimate never depends on the provider being up.
     */
    public function getPricing(): PricingDTO
    {
        $cached = Cache::get(self::PRICING_CACHE_KEY);

        if (is_array($cached)) {
            try {
                return PricingDTO::fromArray($cached);
            } catch (InvalidArgumentException) {
                Cache::forget(self::PRICING_CACHE_KEY);
            }
        }

        try {
            $response = $this->send('GET', 'pricing', fn (PendingRequest $http): Response => $http->get('pricing'), authenticated: false);
            $pricing = $this->parse($response, PricingDTO::fromArray(...));
            $data = $this->json($response);

            Cache::put(self::PRICING_CACHE_KEY, $data, config()->integer('pixelforge.pricing.cache_ttl_seconds'));

            return $pricing;
        } catch (PixelforgeException $exception) {
            Log::warning('Pixelforge pricing unavailable, using the config fallback.', [
                'reason' => $exception->getMessage(),
            ]);

            $fallback = config()->array('pixelforge.pricing.fallback');
            $pricing = PricingDTO::fromArray($fallback);

            Cache::put(self::PRICING_CACHE_KEY, $fallback, config()->integer('pixelforge.pricing.fallback_cache_ttl_seconds'));

            return $pricing;
        }
    }

    /**
     * GET /v1/usage for the configured key: UTC days, both ends inclusive.
     * Ranges longer than the API's 92-day limit are fetched in consecutive
     * chunks and merged, so `date_range_too_long` can only mean a bug.
     *
     * Each chunk must answer exactly the range asked for, with one row per
     * day in order (days without usage are zero rows). A clamped or gappy
     * answer is unreadable (PixelforgeUnavailable), never a report with
     * missing days that reads as zero cost.
     *
     * @throws PixelforgeBusy|PixelforgeUnavailable|PixelforgeRejected|PixelforgeNotConfigured
     */
    public function getUsage(CarbonImmutable $from, CarbonImmutable $to): UsageReportDTO
    {
        $start = $from->utc()->startOfDay();
        $end = $to->utc()->startOfDay();

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('The usage range must not end before it starts.');
        }

        $maxDays = config()->integer('pixelforge.usage_max_days');
        $reports = [];
        $chunkStart = $start;

        while ($chunkStart->lessThanOrEqualTo($end)) {
            $chunkEnd = $chunkStart->addDays($maxDays - 1);
            $chunkEnd = $chunkEnd->greaterThan($end) ? $end : $chunkEnd;

            $query = [
                'start_date' => $chunkStart->format('Y-m-d'),
                'end_date' => $chunkEnd->format('Y-m-d'),
            ];

            $response = $this->send('GET', 'usage', fn (PendingRequest $http): Response => $http->get('usage', $query));
            $reports[] = $this->parse(
                $response,
                fn (array $data): UsageReportDTO => self::assertUsageCovers(UsageReportDTO::fromArray($data), $chunkStart, $chunkEnd),
            );

            $chunkStart = $chunkEnd->addDay();
        }

        // The chunks were checked one by one, so merging cannot fail; if it
        // ever did, it is still a Pixelforge failure, not a raw exception.
        try {
            return UsageReportDTO::merge($reports);
        } catch (InvalidArgumentException $exception) {
            throw new PixelforgeUnavailable("Pixelforge usage chunks could not be merged: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * The report covers exactly [$start, $end] with one row per UTC day,
     * ascending and without gaps or duplicates.
     *
     * @throws InvalidArgumentException
     */
    private static function assertUsageCovers(UsageReportDTO $report, CarbonImmutable $start, CarbonImmutable $end): UsageReportDTO
    {
        if (! $report->startDate->equalTo($start) || ! $report->endDate->equalTo($end)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Pixelforge payload: usage covers %s to %s, asked for %s to %s.',
                $report->startDate->format('Y-m-d'),
                $report->endDate->format('Y-m-d'),
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            ));
        }

        $expected = $start;

        foreach ($report->days as $day) {
            if ($expected->greaterThan($end)) {
                throw new InvalidArgumentException("Invalid Pixelforge payload: usage has a row after {$end->format('Y-m-d')}.");
            }

            if (! $day->date->equalTo($expected)) {
                throw new InvalidArgumentException("Invalid Pixelforge payload: usage day {$day->date->format('Y-m-d')} where {$expected->format('Y-m-d')} was expected.");
            }

            $expected = $expected->addDay();
        }

        if (! $expected->equalTo($end->addDay())) {
            throw new InvalidArgumentException("Invalid Pixelforge payload: usage stops before {$expected->format('Y-m-d')}.");
        }

        return $report;
    }

    /**
     * Sends one request and maps every failure to a Pixelforge exception.
     *
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(string $method, string $endpoint, Closure $call, bool $authenticated = true): Response
    {
        $http = Http::baseUrl(config()->string('pixelforge.base_url'));

        if ($authenticated) {
            $http = $http->withToken($this->apiKey() ?? throw new PixelforgeNotConfigured);
        }

        $http = $http
            ->acceptJson()
            ->timeout(config()->integer('pixelforge.http.timeout_seconds'))
            ->connectTimeout(config()->integer('pixelforge.http.connect_timeout_seconds'));

        $startedAt = hrtime(true);

        try {
            $response = $call($http);
        } catch (ConnectionException $exception) {
            Log::warning('Pixelforge request failed: connection error or timeout.', [
                'method' => $method,
                'endpoint' => $endpoint,
                'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            ]);

            throw PixelforgeUnavailable::connection($exception);
        }

        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'request_id' => $response->header('X-Request-Id') ?: ($response->header('CF-Ray') ?: null),
            'rate_limit_limit' => $response->header('X-RateLimit-Limit') ?: null,
            'rate_limit_remaining' => $response->header('X-RateLimit-Remaining') ?: null,
            'retry_after' => $response->header('Retry-After') ?: null,
            'idempotent_replayed' => $response->header('Idempotent-Replayed') ?: null,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
        ];

        if ($response->successful()) {
            Log::info('Pixelforge request.', $context);

            return $response;
        }

        $exception = $this->exceptionFor($response);
        $context['error_code'] = $exception->errorCode;

        if ($exception instanceof PixelforgeRejected && $exception->isCritical()) {
            Log::critical('Pixelforge rejected a request: configuration problem or bug.', $context);
        } elseif ($exception instanceof PixelforgeRejected) {
            Log::notice('Pixelforge rejected a request.', $context);
        } else {
            Log::warning('Pixelforge is busy or unavailable.', $context);
        }

        throw $exception;
    }

    /**
     * The spec 3-04 error table.
     */
    private function exceptionFor(Response $response): PixelforgeBusy|PixelforgeUnavailable|PixelforgeRejected
    {
        $status = $response->status();
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';

        return match (true) {
            $status === 429 && $code === 'daily_limit_exceeded' => new PixelforgeRejected($code, $status, $message),
            $status === 429 => new PixelforgeBusy($this->retryAfter($response), $code ?? 'rate_limit_exceeded', $status),
            $status === 503 && $code === 'overloaded' => new PixelforgeBusy($this->retryAfter($response), $code, $status),
            $status >= 500 => PixelforgeUnavailable::serverError($status, $code),
            default => new PixelforgeRejected($code ?? "http_{$status}", $status, $message),
        };
    }

    private function retryAfter(Response $response): int
    {
        return RetryAfter::parse($response->header('Retry-After'), CarbonImmutable::now())
            ?? self::DEFAULT_RETRY_AFTER_SECONDS;
    }

    /**
     * @template T
     *
     * @param  Closure(array<mixed>): T  $parser
     * @return T
     */
    private function parse(Response $response, Closure $parser): mixed
    {
        try {
            return $parser($this->json($response));
        } catch (InvalidArgumentException $exception) {
            Log::error('Pixelforge returned a body we could not read.', [
                'status' => $response->status(),
                'reason' => $exception->getMessage(),
            ]);

            // A 2xx we cannot read may still have created a generation: ambiguous.
            throw PixelforgeUnavailable::unreadable($response->status(), $exception);
        }
    }

    /**
     * @return array<mixed>
     */
    private function json(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid Pixelforge payload: the body is not a JSON object.');
        }

        return $data;
    }

    private function apiKey(): ?string
    {
        $key = config('pixelforge.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
