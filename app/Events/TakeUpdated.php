<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Take;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Interface stub (D-068, spec 3-02 issue 3): dispatched whenever a take
 * changes so the live grid can refresh.
 *
 * TODO(spec 5): broadcast on the customer's private channel via Reverb.
 */
final class TakeUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Take $take) {}
}
