<?php

declare(strict_types=1);

use App\Models\Take;
use App\Support\Pixelforge\SubmitGate;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config(['pixelforge.gate.store' => 'database', 'pixelforge.gate.lock_wait_seconds' => 0]);
});

function gate(): SubmitGate
{
    return app(SubmitGate::class);
}

it('lets a take through below the cap and counts it as submitting', function (): void {
    Take::factory()->queued()->create();
    $take = Take::factory()->create();

    $decision = gate()->acquire($take, 'job-a');

    expect($decision->allowed)->toBeTrue()
        ->and($decision->releaseAfterSeconds)->toBe(0)
        ->and(gate()->inFlight())->toBe(2);
});

it('releases with 3-8 s of jitter at 3 in flight, without calling anything', function (): void {
    Take::factory()->queued()->create();
    Take::factory()->processing()->count(2)->create();

    foreach (range(1, 20) as $attempt) {
        $decision = gate()->acquire(Take::factory()->create(), 'job-a');

        expect($decision->allowed)->toBeFalse()
            ->and($decision->releaseAfterSeconds)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(8);
    }
});

it('does not count finished takes or pending takes that are not submitting', function (): void {
    Take::factory()->succeeded()->count(2)->create();
    Take::factory()->failed()->create();
    Take::factory()->count(5)->create(); // pending, waiting for their job

    expect(gate()->inFlight())->toBe(0)
        ->and(gate()->acquire(Take::factory()->create(), 'job-a')->allowed)->toBeTrue();
});

it('counts takes currently submitting until they finish', function (): void {
    Take::factory()->queued()->count(2)->create();
    $submitting = Take::factory()->create();
    $waiting = Take::factory()->create();

    expect(gate()->acquire($submitting, 'job-a')->allowed)->toBeTrue()
        ->and(gate()->acquire($waiting, 'job-a')->allowed)->toBeFalse();

    gate()->finish($submitting, 'job-a');

    expect(gate()->acquire($waiting, 'job-a')->allowed)->toBeTrue();
});

it('does not count a take against itself when its job retries', function (): void {
    Take::factory()->queued()->count(2)->create();
    $take = Take::factory()->create();

    expect(gate()->acquire($take, 'job-a')->allowed)->toBeTrue()
        ->and(gate()->acquire($take, 'job-a')->allowed)->toBeTrue()
        ->and(gate()->inFlight())->toBe(3);
});

it('counts a submitted take once, by its pixelforge_id', function (): void {
    $take = Take::factory()->create();
    gate()->acquire($take, 'job-a');

    $take->forceFill(['status' => 'queued', 'pixelforge_id' => 'gen_abc'])->save();

    expect(gate()->inFlight())->toBe(1);
});

it('expires a reservation whose job never reported back', function (): void {
    Take::factory()->queued()->count(2)->create();
    expect(gate()->acquire(Take::factory()->create(), 'job-a')->allowed)->toBeTrue();

    $waiting = Take::factory()->create();
    expect(gate()->acquire($waiting, 'job-a')->allowed)->toBeFalse();

    $this->travel(config('pixelforge.gate.reservation_seconds') + 1)->seconds();

    expect(gate()->acquire($waiting, 'job-a')->allowed)->toBeTrue();
});

it('releases the job when another worker holds the gate lock', function (): void {
    $lock = Cache::store('database')->lock(SubmitGate::LOCK_KEY, 10);
    expect($lock->get())->toBeTrue();

    $decision = gate()->acquire(Take::factory()->create(), 'job-a');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->releaseAfterSeconds)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(8);

    $lock->release();
});

it('keeps a slot for the job that reserved it: another job neither takes it nor frees it', function (): void {
    $take = Take::factory()->create();

    expect(gate()->acquire($take, 'job-a')->allowed)->toBeTrue()
        ->and(gate()->acquire($take, 'job-b')->allowed)->toBeFalse();

    gate()->finish($take, 'job-b');

    expect(gate()->isSubmitting($take))->toBeTrue();

    gate()->finish($take, 'job-a');

    expect(gate()->isSubmitting($take))->toBeFalse()
        ->and(gate()->acquire($take, 'job-b')->allowed)->toBeTrue();
});
