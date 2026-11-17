<?php

declare(strict_types=1);

namespace Tests\Support;

use Error;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Queue\Attributes\Delay;
use LogicException;
use ReflectionClass;

/**
 * Every delay a queued class declares up front, in seconds: `backoff()` or
 * `$backoff`, the `$delay` default and the #[Delay], #[Backoff] and
 * #[DebounceFor] attributes. Laravel Cloud's managed queue rejects anything
 * above 900 s (spec 0, "Queue"). Covered by tests/Unit/QueueDelaysTest.php.
 *
 * Values assigned in the constructor (`$this->delay = ...`) are invisible
 * here; SourceScan::queueDelayViolations() checks those assignments.
 *
 * @throws LogicException when backoff() cannot run on a constructor-less instance
 */
final class QueueDelays
{
    /**
     * @param  class-string|object  $job
     * @return list<int>
     */
    public static function declaredSeconds(string|object $job): array
    {
        $reflection = new ReflectionClass($job);
        $instance = is_object($job) ? $job : $reflection->newInstanceWithoutConstructor();
        $values = [];

        if (method_exists($instance, 'backoff')) {
            try {
                $values[] = $instance->backoff();
            } catch (Error $error) {
                // backoff() depends on constructor state the reflection-built
                // instance does not have, so it cannot be checked statically.
                throw new LogicException(
                    "{$reflection->getName()}::backoff() is not statically verifiable without the constructor ({$error->getMessage()}). Return literal values or use #[Backoff].",
                    previous: $error,
                );
            }
        } elseif ($reflection->hasProperty('backoff')) {
            $values[] = $reflection->getProperty('backoff')->getValue($instance);
        }

        if ($reflection->hasProperty('delay')) {
            $values[] = $reflection->getProperty('delay')->getValue($instance);
        }

        foreach ($reflection->getAttributes(Delay::class) as $attribute) {
            $values[] = $attribute->newInstance()->delay;
        }

        foreach ($reflection->getAttributes(Backoff::class) as $attribute) {
            $values[] = $attribute->newInstance()->backoff;
        }

        foreach ($reflection->getAttributes(DebounceFor::class) as $attribute) {
            $debounce = $attribute->newInstance();
            $values[] = [$debounce->debounceFor, $debounce->maxWait];
        }

        $seconds = [];

        array_walk_recursive($values, function (mixed $value) use (&$seconds): void {
            if (is_int($value)) {
                $seconds[] = $value;
            }
        });

        return $seconds;
    }
}
