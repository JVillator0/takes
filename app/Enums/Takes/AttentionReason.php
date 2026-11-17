<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * Durable "Needs attention" flag on a take (D-054). The view itself is derived in spec 7.
 */
enum AttentionReason: string
{
    case InvalidSnapshot = 'invalid_snapshot';
    case OrphanSuccess = 'orphan_success';
    case CostMismatch = 'cost_mismatch';
    case ReconcileExhausted = 'reconcile_exhausted';
    case InternalFailure = 'internal_failure';
    case OverEstimate = 'over_estimate';
}
