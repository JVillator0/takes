<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

/**
 * What the submit gate tells the submit job: call Pixelforge now, or
 * release the job for `releaseAfterSeconds` without calling it.
 */
final readonly class SubmitGateDecision
{
    private function __construct(
        public bool $allowed,
        public int $releaseAfterSeconds,
    ) {}

    public static function allow(): self
    {
        return new self(true, 0);
    }

    public static function releaseAfter(int $seconds): self
    {
        return new self(false, max(0, $seconds));
    }
}
