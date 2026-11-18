<?php

declare(strict_types=1);

namespace App\Enums\Takes;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Durable "Needs attention" flag on a take (D-054). The view itself is derived in spec 7 (the dashboard's Needs
 * attention table, which adds stuck takes from the D-053 rule).
 */
enum AttentionReason: string implements HasColor, HasLabel
{
    case InvalidSnapshot = 'invalid_snapshot';
    case OrphanSuccess = 'orphan_success';
    case CostMismatch = 'cost_mismatch';
    case ReconcileExhausted = 'reconcile_exhausted';
    case InternalFailure = 'internal_failure';
    case OverEstimate = 'over_estimate';

    /**
     * Whether this reason may take the single attention_reason column from
     * $current. An orphan success is never overwritten by another reason:
     * it marks absorbed provider cost that reconciliation must keep showing
     * in its own group (D-058).
     */
    public function replaces(?self $current): bool
    {
        return $current !== self::OrphanSuccess || $this === self::OrphanSuccess;
    }

    /** Admin console label (Filament HasLabel), from lang/en/console.php. */
    public function getLabel(): string
    {
        $label = __('console.enums.attention_reason.'.$this->value);

        return is_string($label) ? $label : $this->value;
    }

    /** Admin console badge color (Filament HasColor): absorbed cost and lost money first. */
    public function getColor(): string
    {
        return match ($this) {
            self::OrphanSuccess, self::CostMismatch, self::OverEstimate => 'danger',
            self::InvalidSnapshot, self::ReconcileExhausted, self::InternalFailure => 'warning',
        };
    }
}
