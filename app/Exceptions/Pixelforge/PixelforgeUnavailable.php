<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use Throwable;

/**
 * Connection error, timeout, any other 5xx, or a 2xx we could not read.
 *
 * Ambiguous: the generation may exist. The caller retries with the SAME
 * idempotency key (a replay is free), `last_submit_error_kind = ambiguous`.
 */
final class PixelforgeUnavailable extends PixelforgeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connection(Throwable $previous): self
    {
        return new self('Could not reach Pixelforge (connection error or timeout).', previous: $previous);
    }

    public static function serverError(int $status, ?string $errorCode): self
    {
        return new self("Pixelforge answered {$status}".($errorCode !== null ? " {$errorCode}" : '').'.', $status, $errorCode);
    }

    public static function unreadable(int $status, Throwable $previous): self
    {
        return new self("Pixelforge answered {$status} with a body we could not read: {$previous->getMessage()}", $status, previous: $previous);
    }
}
