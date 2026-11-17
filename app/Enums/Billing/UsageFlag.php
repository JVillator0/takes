<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Durable "Needs attention" flags on a usage record (`usage_records.flags`, D-054).
 */
enum UsageFlag: string
{
    /** The actual charge exceeded the hold (estimator bug or stale pricing, spec 3-03 §6). */
    case OverEstimate = 'over_estimate';

    /** The provider-reported cost disagreed with units × rates (spec 3-03 §2). */
    case CostMismatch = 'cost_mismatch';

    /** A late success for a take failed locally with submit_timeout (D-025). */
    case Orphan = 'orphan';
}
