<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Enums\Auth\DenialReason;
use RuntimeException;

/**
 * An action refused by its policy, re-checked under lock (D-015): the
 * caller renders it as a denial with the reason code (D-050), never as a
 * failure. Thrown by CreateTakeAction for caps, restrictions and credits.
 */
final class ActionDeniedException extends RuntimeException
{
    public function __construct(public readonly DenialReason $reason)
    {
        parent::__construct("Denied: {$reason->value}.");
    }
}
