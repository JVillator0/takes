<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Auth\CustomerRole;
use App\Jobs\SubmitTakeJob;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;

/**
 * Spec 4 fixtures: a funded customer with the `customer` role and a
 * project, the composer's POST body, a faked Pixelforge (pricing plus a
 * create response) and a SubmitTakeJob run on a fake queue job, so its
 * release() delays can be read. Pool worktrees never call the real API
 * (CLAUDE.md): every test fakes HTTP.
 */
final class TakeFlow
{
    public const string API = 'https://pixelforge.promptedit.dev/v1';

    /** The default payload: "Night version with stars", square, medium, no references (take #11 of the demo dataset). */
    public const string PROMPT = 'Night version with stars';

    /** Its hold: max cost 69,035,000 × 1.25 (tests/Unit/Pricing/TakeEstimatorTest.php). */
    public const int HOLD = 86_293_750;

    public const int MIN_CHARGE = 58_156_250;

    public static function configure(): void
    {
        config([
            'pixelforge.base_url' => self::API,
            'pixelforge.api_key' => 'pf_test_key',
            'pixelforge.gate.store' => 'database',
            'pixelforge.gate.lock_wait_seconds' => 0,
        ]);
    }

    public static function customer(int $nanos = Takes::FUNDED_NANOS): Customer
    {
        $customer = Customer::factory()->withRole(CustomerRole::Customer)->create();

        if ($nanos > 0) {
            Billing::fund($customer, $nanos);
        }

        return $customer->refresh();
    }

    public static function project(Customer $customer): Project
    {
        return Project::factory()->create(['customer_id' => $customer->id]);
    }

    /**
     * The composer's POST body (P04).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return [
            'prompt' => self::PROMPT,
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    /**
     * Fakes GET /v1/pricing (the documented values) and POST /v1/generations.
     *
     * @param  (Closure(Request): PromiseInterface)|PromiseInterface|null  $create  the create response; default a 202 queued generation for the take
     */
    public static function fakePixelforge(Closure|PromiseInterface|null $create = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::API.'/pricing' => Http::response(PixelforgeFixture::json('pricing')),
            self::API.'/generations' => $create ?? fn ($request) => self::accepted($request->data()),
        ]);
    }

    /**
     * A 202 for the create request: the queued generation carrying our metadata.
     *
     * @param  array<string, mixed>  $body
     */
    public static function accepted(array $body, bool $replayed = false): PromiseInterface
    {
        /** @var array{app_take_id: string, app_customer_id: string} $metadata */
        $metadata = $body['metadata'];

        return Http::response(PixelforgeFixture::generation('queued', [
            'id' => 'gen_'.strtolower($metadata['app_take_id']),
            'metadata' => $metadata,
        ]), 202, $replayed ? ['Idempotent-Replayed' => 'true'] : []);
    }

    public static function error(string $code, int $status, ?string $retryAfter = null): PromiseInterface
    {
        return Http::response(PixelforgeFixture::error($code), $status, $retryAfter !== null ? ['Retry-After' => $retryAfter] : []);
    }

    /**
     * Runs the job's handle() on a fake queue job and returns the release
     * delay it asked for (null when it did not release).
     */
    public static function runSubmit(Take $take): ?int
    {
        $released = null;
        /** @var Job&MockInterface $queueJob */
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->andReturnUsing(function (int $delay) use (&$released): void {
            $released = $delay;
        });
        $queueJob->shouldReceive('isReleased')->andReturn(false);
        $queueJob->shouldReceive('isDeleted')->andReturn(false);
        $queueJob->shouldReceive('hasFailed')->andReturn(false);

        $job = new SubmitTakeJob($take->id);
        $job->setJob($queueJob);
        app()->call([$job, 'handle']);

        return $released;
    }
}
