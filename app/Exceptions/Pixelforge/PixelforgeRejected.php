<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use App\Enums\Takes\FailureCode;

/**
 * A definite rejection: nothing was created or billed and retrying the same
 * request cannot help. The caller fails the take free now (D-025).
 *
 *   400 content_policy_violation ⇒ content_policy
 *   422 validation_failed        ⇒ validation
 *   429 daily_limit_exceeded     ⇒ daily_limit
 *   401, 404, 409, anything else ⇒ internal + critical log (D-062): a
 *   configuration problem or a bug on our side, never the customer's.
 */
final class PixelforgeRejected extends PixelforgeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message = '',
    ) {
        parent::__construct("Pixelforge rejected the request ({$status} {$errorCode})".($message !== '' ? ": {$message}" : '.'));
    }

    /** The take's failure_code for this rejection (D-049). */
    public function failureCode(): FailureCode
    {
        return match ($this->errorCode) {
            'content_policy_violation' => FailureCode::ContentPolicy,
            'validation_failed' => FailureCode::Validation,
            'daily_limit_exceeded' => FailureCode::DailyLimit,
            default => FailureCode::Internal,
        };
    }

    /** True when this is our problem (revoked key, reused key, bug): alert, Needs attention. */
    public function isCritical(): bool
    {
        return $this->failureCode() === FailureCode::Internal;
    }
}
