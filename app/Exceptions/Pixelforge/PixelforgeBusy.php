<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * 429 `rate_limit_exceeded` / `concurrency_limit_exceeded`, 503 `overloaded`.
 *
 * Definite non-creation: nothing was created or billed. The caller keeps the
 * take pending (`last_submit_error_kind = capacity`) and retries after
 * `retryAfter` seconds plus jitter, capped by the caller at 900 s (spec 4).
 */
final class PixelforgeBusy extends PixelforgeException
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct("Pixelforge is busy ({$status} {$errorCode}); retry after {$retryAfter}s.");
    }
}
