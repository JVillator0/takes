<?php

declare(strict_types=1);

use App\DTOs\Pixelforge\CreateGenerationDTO;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Pixelforge\PixelforgeBusy;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Exceptions\Pixelforge\PixelforgeRejected;
use App\Exceptions\Pixelforge\PixelforgeUnavailable;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\PixelforgeFixture;

const TEST_KEY = 'pf_test_0123456789abcdef';
const IDEMPOTENCY_KEY = '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10';

beforeEach(function (): void {
    config([
        'pixelforge.base_url' => 'https://pixelforge.test/v1',
        'pixelforge.api_key' => TEST_KEY,
    ]);
    Cache::flush();
    Http::preventStrayRequests();
});

function client(): PixelforgeClient
{
    return app(PixelforgeClient::class);
}

function createRequest(): CreateGenerationDTO
{
    return new CreateGenerationDTO(
        prompt: 'A lighthouse on a cliff at dawn, oil painting',
        size: TakeSize::Landscape,
        quality: TakeQuality::High,
        referenceImages: ['https://example.com/style.png'],
        appTakeId: '01JTAKE0000000000000000000',
        appCustomerId: '42',
    );
}

/**
 * Runs $call and returns the Pixelforge exception it threw.
 */
function pixelforgeFailure(Closure $call): PixelforgeException
{
    try {
        $call();
    } catch (PixelforgeException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a Pixelforge exception.');
}

describe('createGeneration', function (): void {
    it('posts the request with the key, the idempotency key and our metadata', function (): void {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixture::generation('queued'), 202)]);

        $snapshot = client()->createGeneration(createRequest(), IDEMPOTENCY_KEY);

        expect($snapshot->status)->toBe(TakeStatus::Queued)
            ->and($snapshot->replayed)->toBeFalse()
            ->and($snapshot->usage)->toBeNull();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer '.TEST_KEY)
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Idempotency-Key', IDEMPOTENCY_KEY)
            && $request->data() === createRequest()->toArray());
    });

    it('replays after a timeout with the same key and reports replayed = true', function (): void {
        Http::fakeSequence('pixelforge.test/v1/generations')
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->push(PixelforgeFixture::generation('queued', ['status' => 'processing', 'started_at' => '2026-11-04T14:03:13Z']), 202, ['Idempotent-Replayed' => 'true']);

        expect(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY))
            ->toThrow(PixelforgeUnavailable::class);

        $snapshot = client()->createGeneration(createRequest(), IDEMPOTENCY_KEY);

        expect($snapshot->replayed)->toBeTrue()
            ->and($snapshot->status)->toBe(TakeStatus::Processing);

        $keys = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0]);
        expect($keys->all())->toBe([IDEMPOTENCY_KEY, IDEMPOTENCY_KEY]);
    });

    it('never calls Pixelforge without an API key', function (): void {
        config(['pixelforge.api_key' => '']);
        Http::fake();

        expect(client()->isConfigured())->toBeFalse()
            ->and(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY))->toThrow(PixelforgeNotConfigured::class);

        Http::assertNothingSent();
    });

    it('treats a 2xx it cannot read as ambiguous', function (): void {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

        expect(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY))
            ->toThrow(PixelforgeUnavailable::class);
    });
});

describe('error table (spec 3-04)', function (): void {
    it('maps capacity responses to PixelforgeBusy with Retry-After', function (int $status, string $code, string $retryAfter, int $expected): void {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixture::error($code), $status, ['Retry-After' => $retryAfter])]);

        $exception = pixelforgeFailure(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY));

        expect($exception)->toBeInstanceOf(PixelforgeBusy::class)
            ->and($exception->errorCode)->toBe($code)
            ->and($exception->retryAfter)->toBe($expected)
            ->and($exception->status)->toBe($status);
    })->with([
        '429 rate_limit_exceeded' => [429, 'rate_limit_exceeded', '17', 17],
        '429 concurrency_limit_exceeded' => [429, 'concurrency_limit_exceeded', '5', 5],
        '503 overloaded' => [503, 'overloaded', '2', 2],
    ]);

    it('reads Retry-After given as an HTTP-date', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-17T17:21:31Z'));
        Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixture::error('rate_limit_exceeded'), 429, [
            'Retry-After' => 'Tue, 17 Nov 2026 17:22:16 GMT',
        ])]);

        $exception = pixelforgeFailure(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY));

        expect($exception)->toBeInstanceOf(PixelforgeBusy::class)
            ->and($exception->retryAfter)->toBe(45);
    });

    it('falls back to 5 seconds when Retry-After is missing', function (): void {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixture::error('concurrency_limit_exceeded'), 429)]);

        $exception = pixelforgeFailure(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY));

        expect($exception)->toBeInstanceOf(PixelforgeBusy::class)
            ->and($exception->retryAfter)->toBe(PixelforgeClient::DEFAULT_RETRY_AFTER_SECONDS);
    });

    it('maps connection errors, timeouts and other 5xx to PixelforgeUnavailable', function (Closure $response): void {
        Http::fake(['pixelforge.test/v1/generations' => $response]);

        expect(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY))
            ->toThrow(PixelforgeUnavailable::class);
    })->with([
        'timeout' => [fn () => Http::failedConnection('cURL error 28: Operation timed out')],
        'connection refused' => [fn () => Http::failedConnection('cURL error 7: Failed to connect')],
        '500' => [fn () => Http::response(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'Oops']], 500)],
        '502 without a body' => [fn () => Http::response('Bad gateway', 502)],
        '503 that is not "overloaded"' => [fn () => Http::response('<html>Service unavailable</html>', 503)],
        '504' => [fn () => Http::response('', 504)],
    ]);

    it('maps definite rejections to PixelforgeRejected with the take failure code', function (int $status, string $code, FailureCode $failureCode, bool $critical): void {
        Log::spy();
        Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixture::error($code), $status)]);

        $exception = pixelforgeFailure(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY));

        expect($exception)->toBeInstanceOf(PixelforgeRejected::class)
            ->and($exception->errorCode)->toBe($code)
            ->and($exception->status)->toBe($status)
            ->and($exception->failureCode())->toBe($failureCode)
            ->and($exception->isCritical())->toBe($critical);

        if ($critical) {
            Log::shouldHaveReceived('critical')->once();
        } else {
            Log::shouldNotHaveReceived('critical');
        }
    })->with([
        '429 daily_limit_exceeded' => [429, 'daily_limit_exceeded', FailureCode::DailyLimit, false],
        '400 content_policy_violation' => [400, 'content_policy_violation', FailureCode::ContentPolicy, false],
        '422 validation_failed' => [422, 'validation_failed', FailureCode::Validation, false],
        '401 invalid_api_key' => [401, 'invalid_api_key', FailureCode::Internal, true],
        '409 idempotency_key_reused' => [409, 'idempotency_key_reused', FailureCode::Internal, true],
    ]);

    it('maps the documented validation body and the real 401 body', function (string $fixture, int $status, FailureCode $failureCode): void {
        Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixture::json($fixture), $status)]);

        $exception = pixelforgeFailure(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY));

        expect($exception)->toBeInstanceOf(PixelforgeRejected::class)
            ->and($exception->failureCode())->toBe($failureCode);
    })->with([
        'docs validation_failed' => ['error-validation-failed', 422, FailureCode::Validation],
        'real invalid_api_key' => ['error-invalid-api-key', 401, FailureCode::Internal],
    ]);

    it('maps 404 not_found to an internal rejection with a critical log', function (): void {
        Log::spy();
        Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::error('not_found'), 404)]);

        $exception = pixelforgeFailure(fn () => client()->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'));

        expect($exception)->toBeInstanceOf(PixelforgeRejected::class)
            ->and($exception->errorCode)->toBe('not_found')
            ->and($exception->failureCode())->toBe(FailureCode::Internal);

        Log::shouldHaveReceived('critical')->once();
    });
});

describe('logging', function (): void {
    it('logs status, request id and rate-limit headers, never the API key', function (): void {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        Http::fake([
            'pixelforge.test/v1/generations' => Http::sequence()
                ->push(PixelforgeFixture::generation('queued'), 202, ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '58', 'X-Request-Id' => 'req_123'])
                ->push(PixelforgeFixture::error('invalid_api_key'), 401)
                ->pushFailedConnection(),
        ]);

        client()->createGeneration(createRequest(), IDEMPOTENCY_KEY);
        rescue(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY), report: false);
        rescue(fn () => client()->createGeneration(createRequest(), IDEMPOTENCY_KEY), report: false);

        expect($logged)->toHaveCount(3)
            ->and($logged[0]->level)->toBe('info')
            ->and($logged[0]->context)->toMatchArray([
                'method' => 'POST',
                'endpoint' => 'generations',
                'status' => 202,
                'request_id' => 'req_123',
                'rate_limit_limit' => '60',
                'rate_limit_remaining' => '58',
            ])
            ->and($logged[1]->level)->toBe('critical')
            ->and($logged[1]->context['error_code'])->toBe('invalid_api_key')
            ->and($logged[2]->level)->toBe('warning');

        $everything = json_encode(array_map(fn (MessageLogged $event): array => [$event->message, $event->context], $logged), JSON_THROW_ON_ERROR);
        expect($everything)->not->toContain(TEST_KEY);
    });
});

describe('getGeneration and listGenerations', function (): void {
    it('fetches one generation', function (): void {
        Http::fake(['pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(PixelforgeFixture::generation('succeeded'))]);

        $snapshot = client()->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        expect($snapshot->status)->toBe(TakeStatus::Succeeded)
            ->and($snapshot->usage?->outputTokens)->toBe(6450)
            ->and($snapshot->replayed)->toBeFalse();
    });

    it('refuses an id that is not a generation id', function (): void {
        expect(fn () => client()->getGeneration('../usage'))->toThrow(InvalidArgumentException::class);
    });

    it('pages through every generation by cursor', function (): void {
        Http::fake(['pixelforge.test/v1/generations*' => Http::sequence()
            ->push([
                'object' => 'list',
                'data' => [
                    PixelforgeFixture::generation('succeeded', ['id' => 'gen_3']),
                    PixelforgeFixture::generation('succeeded', ['id' => 'gen_2']),
                ],
                'has_more' => true,
                'next_cursor' => 'eyJjcmVhdGVkX2F0Ijoi',
            ])
            ->push([
                'object' => 'list',
                'data' => [PixelforgeFixture::generation('succeeded', ['id' => 'gen_1'])],
                'has_more' => false,
                'next_cursor' => null,
            ]),
        ]);

        $ids = [];
        $cursor = null;

        do {
            $page = client()->listGenerations('succeeded', $cursor);
            array_push($ids, ...array_map(fn ($generation): string => $generation->id, $page->generations));
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        expect($ids)->toBe(['gen_3', 'gen_2', 'gen_1']);

        $queries = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();
        expect($queries)->toBe([
            ['limit' => 100, 'status' => 'succeeded'],
            ['limit' => 100, 'status' => 'succeeded', 'cursor' => 'eyJjcmVhdGVkX2F0Ijoi'],
        ]);
    });

    it('validates the list filters before calling', function (): void {
        Http::fake();

        expect(fn () => client()->listGenerations('pending'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => client()->listGenerations(null, null, 101))->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    });
});

describe('getPricing', function (): void {
    it('fetches the public pricing once and caches it for an hour', function (): void {
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(PixelforgeFixture::json('pricing'))]);

        $first = client()->getPricing();
        $second = client()->getPricing();

        expect($first->rates->output->toInt())->toBe(40_000_000_000)
            ->and($first->textTokenVarianceBps)->toBe(1500)
            ->and($second)->toEqual($first);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));

        $this->travel(61)->minutes();
        client()->getPricing();
        Http::assertSentCount(2);
    });

    it('works without an API key (the endpoint is public)', function (): void {
        config(['pixelforge.api_key' => null]);
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(PixelforgeFixture::json('pricing'))]);

        expect(client()->getPricing()->tokensPerReferenceImage)->toBe(765);
    });

    it('serves the config fallback when Pixelforge cannot answer', function (Closure $response): void {
        config(['pixelforge.pricing.fallback.rates.output_tokens' => '41.00']);
        Http::fake(['pixelforge.test/v1/pricing' => $response]);

        $pricing = client()->getPricing();

        expect($pricing->rates->output->toInt())->toBe(41_000_000_000)
            ->and($pricing->textTokenVarianceBps)->toBe(1500);

        client()->getPricing();
        Http::assertSentCount(1);
    })->with([
        'timeout' => [fn () => Http::failedConnection()],
        '503' => [fn () => Http::response(PixelforgeFixture::error('overloaded'), 503)],
        'unreadable body' => [fn () => Http::response(['object' => 'pricing'])],
    ]);
});

describe('getUsage', function (): void {
    /**
     * A /v1/usage fake that answers any range with one zero row per day,
     * except one billed day per chunk ("0.000000001" × chunk number).
     */
    function fakeUsage(): void
    {
        Http::fake(['pixelforge.test/v1/usage*' => function (Request $request) {
            $query = $request->data();
            $start = CarbonImmutable::parse((string) $query['start_date'], 'UTC');
            $end = CarbonImmutable::parse((string) $query['end_date'], 'UTC');

            $days = [];
            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                $days[] = [
                    'date' => $day->format('Y-m-d'),
                    'generations' => $day->equalTo($start) ? 1 : 0,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => $day->equalTo($start) ? '0.373875000' : '0.000000000',
                ];
            }

            return Http::response([
                'object' => 'usage',
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'data' => $days,
                'total' => [],
            ]);
        }]);
    }

    it('parses the documented usage report with exact nine-decimal costs', function (): void {
        Http::fake(['pixelforge.test/v1/usage*' => Http::response(PixelforgeFixture::json('usage'))]);

        $report = client()->getUsage(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'));

        expect($report->days)->toHaveCount(2)
            ->and($report->days[0]->cost->toInt())->toBe(169_080_000)
            ->and($report->days[1]->cost->isZero())->toBeTrue()
            ->and($report->totalCost()->toDecimalString())->toBe('0.169080000');

        Http::assertSent(fn (Request $request): bool => $request->data() === ['start_date' => '2026-11-01', 'end_date' => '2026-11-02']
            && $request->hasHeader('Authorization', 'Bearer '.TEST_KEY));
    });

    it('asks for a 92-day range in one request', function (): void {
        fakeUsage();

        $report = client()->getUsage(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-04-02'));

        expect($report->days)->toHaveCount(92);
        Http::assertSentCount(1);
    });

    it('chunks longer ranges into consecutive 92-day requests and merges them', function (): void {
        fakeUsage();

        $report = client()->getUsage(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-06-30'));

        $ranges = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();
        expect($ranges)->toBe([
            ['start_date' => '2026-01-01', 'end_date' => '2026-04-02'],
            ['start_date' => '2026-04-03', 'end_date' => '2026-06-30'],
        ]);

        $dates = array_map(fn ($day): string => $day->date->format('Y-m-d'), $report->days);
        expect($report->days)->toHaveCount(181)
            ->and($dates[0])->toBe('2026-01-01')
            ->and($dates[180])->toBe('2026-06-30')
            ->and(count(array_unique($dates)))->toBe(181)
            ->and($report->startDate->format('Y-m-d'))->toBe('2026-01-01')
            ->and($report->endDate->format('Y-m-d'))->toBe('2026-06-30')
            ->and($report->totalCost()->toInt())->toBe(2 * 373_875_000)
            ->and($report->totalGenerations())->toBe(2);
    });

    it('uses UTC days, both ends inclusive', function (): void {
        fakeUsage();

        // 2026-11-01 20:00 in El Salvador is already 2026-11-02 in UTC.
        $report = client()->getUsage(
            CarbonImmutable::parse('2026-11-01 20:00', 'America/El_Salvador'),
            CarbonImmutable::parse('2026-11-03 10:00', 'UTC'),
        );

        expect($report->days)->toHaveCount(2);
        Http::assertSent(fn (Request $request): bool => $request->data() === ['start_date' => '2026-11-02', 'end_date' => '2026-11-03']);
    });

    it('rejects a range that ends before it starts', function (): void {
        Http::fake();

        expect(fn () => client()->getUsage(CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-01')))
            ->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    });
});
