<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Enums\Takes\AttentionReason;
use App\Models\Take;

/**
 * Sets the durable "Needs attention" flag on a take (D-054) for findings made
 * outside the transition actions: a webhook whose generation cannot be read
 * (`invalid_snapshot`) or a reconcile chain that ran out (`reconcile_exhausted`).
 * Never touches status or money.
 */
final readonly class FlagTakeAttentionAction
{
    public function execute(Take $take, AttentionReason $reason): void
    {
        Take::query()->whereKey($take->id)->update(['attention_reason' => $reason->value]);

        $take->attention_reason = $reason;
    }
}
