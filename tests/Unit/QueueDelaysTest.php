<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Queue\Attributes\Delay;
use Tests\Support\QueueDelays;

it('reads the backoff property and the delay default', function (): void {
    $job = new class implements ShouldQueue
    {
        use Queueable;

        /** @var list<int> */
        public array $backoff = [30, 300, 900];
    };

    expect(QueueDelays::declaredSeconds($job))->toBe([30, 300, 900]);
});

it('prefers the backoff() method', function (): void {
    $job = new class implements ShouldQueue
    {
        use Queueable;

        public int $backoff = 1;

        /** @return list<int> */
        public function backoff(): array
        {
            return [60, 1200];
        }
    };

    expect(QueueDelays::declaredSeconds($job))->toBe([60, 1200]);
});

it('reads the #[Delay], #[Backoff] and #[DebounceFor] attributes', function (): void {
    $job = new #[Delay(1000), Backoff(10, 2000), DebounceFor(30, maxWait: 3000)] class implements ShouldQueue
    {
        use Queueable;
    };

    expect(QueueDelays::declaredSeconds($job))->toBe([1000, 10, 2000, 30, 3000]);
});

it('reads a single-value #[Backoff]', function (): void {
    $job = new #[Backoff(901)] class implements ShouldQueue
    {
        use Queueable;
    };

    expect(QueueDelays::declaredSeconds($job))->toBe([901]);
});

it('reads a class name without calling its constructor', function (): void {
    expect(QueueDelays::declaredSeconds(QueueDelaysFixtureJob::class))->toBe([120, 7200]);
});

it('returns nothing for a job without delays', function (): void {
    $job = new class implements ShouldQueue
    {
        use Queueable;
    };

    expect(QueueDelays::declaredSeconds($job))->toBe([]);
});

#[Delay(7200)]
final class QueueDelaysFixtureJob implements ShouldQueue
{
    use Queueable;

    public int $backoff = 120;

    public function __construct(public string $required) {}
}
