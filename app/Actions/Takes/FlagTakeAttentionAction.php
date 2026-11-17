<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Enums\Takes\AttentionReason;
use App\Models\Take;

/**
 * Sets the durable "Needs attention" flag on a take (D-054) for findings made
 * outside the transition actions: a webhook whose generation cannot be read
 * (`invalid_snapshot`) or a reconcile chain that stopped (`reconcile_exhausted`,
 * `invalid_snapshot`). Never touches status or money.
 *
 * The column holds one reason, and `orphan_success` is never overwritten by
 * another one (AttentionReason::replaces(), D-077(c), D-058). The rule is
 * applied in the UPDATE itself, so a concurrent orphan flag is never lost.
 */
final readonly class FlagTakeAttentionAction
{
    /**
     * @return bool whether the flag was written
     */
    public function execute(Take $take, AttentionReason $reason): bool
    {
        $written = Take::query()
            ->whereKey($take->id)
            ->when(
                ! $reason->replaces(AttentionReason::OrphanSuccess),
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('attention_reason')
                    ->orWhere('attention_reason', '!=', AttentionReason::OrphanSuccess->value)),
            )
            ->update(['attention_reason' => $reason->value]) === 1;

        if ($written) {
            $take->attention_reason = $reason;
        }

        return $written;
    }
}
