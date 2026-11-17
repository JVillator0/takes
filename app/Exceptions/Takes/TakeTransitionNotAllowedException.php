<?php

declare(strict_types=1);

namespace App\Exceptions\Takes;

use App\Enums\Takes\FailureCode;
use App\Models\Take;
use LogicException;

/**
 * FailTakeAction was asked for a local failure that spec 3-02 does not allow.
 * Nothing was written. Callers check first (CancelTakeAction answers 422
 * `cannot_cancel`); this is the guarantee under the take lock.
 */
final class TakeTransitionNotAllowedException extends LogicException
{
    /** generation_failed and internal_error only ever come from a provider snapshot. */
    public static function providerCode(FailureCode $code): self
    {
        return new self("[{$code->value}] is a provider outcome; it comes from ApplyTakeSnapshotAction, never from a local failure.");
    }

    /** Cancel is allowed only while waiting for capacity: nothing was created at Pixelforge (D-044). */
    public static function cancel(Take $take): self
    {
        $kind = $take->last_submit_error_kind->value ?? 'none';

        return new self("Take {$take->id} cannot be cancelled: it is {$take->status->value} with submit error kind [{$kind}].");
    }
}
