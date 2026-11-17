<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

use App\Enums\Takes\ReconcileStatus;

/**
 * The result of one reconcile poll: what was found and, when Pixelforge asked
 * us to slow down, how long to wait (Retry-After, seconds).
 */
final readonly class ReconcileResultDTO
{
    public function __construct(
        public ReconcileStatus $status,
        public ?int $retryAfterSeconds = null,
    ) {}

    /**
     * @param  array{status: string, retry_after_seconds?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: ReconcileStatus::from($data['status']),
            retryAfterSeconds: $data['retry_after_seconds'] ?? null,
        );
    }
}
