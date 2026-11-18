<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Models\Take;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Needs attention (A02, D-054), the take entries: every take carrying a durable `attention_reason` (written by
 * specs 3-02, 3-03 and 5; this only reads it) plus every stuck take (D-053, ListStuckTakesQuery), the longest
 * silent first. A derived view over the database: it never calls the provider (FI-11).
 */
final readonly class ListTakesNeedingAttentionQuery
{
    public function __construct(private ListStuckTakesQuery $stuckTakes) {}

    /**
     * @return Collection<int, Take>
     */
    public function execute(): Collection
    {
        return $this->builder()->get();
    }

    /**
     * @return Builder<Take>
     */
    public function builder(): Builder
    {
        return Take::query()
            ->with(['customer', 'project'])
            ->where(fn (Builder $attention): Builder => $attention
                ->whereNotNull('attention_reason')
                ->orWhere(fn (Builder $stuck): Builder => $this->stuckTakes->constrain($stuck)))
            ->orderByRaw('coalesce(last_event_at, submitted_at, created_at) asc');
    }
}
