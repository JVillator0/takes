<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Stuck takes, defined once (spec 7 Definitions, D-053): a `queued`/`processing` take with no provider update for
 * more than 15 minutes (`last_event_at`, or `submitted_at` / `created_at` when no update ever came), or a
 * `pending` take created more than 35 minutes ago (the 30-minute retry window plus grace). A pending retry inside
 * its window is not stuck. Reads only the database.
 */
final class ListStuckTakesQuery
{
    public const int SILENT_MINUTES = 15;

    public const int PENDING_MINUTES = 35;

    /**
     * @return Collection<int, Take>
     */
    public function execute(): Collection
    {
        return $this->builder()->latest('created_at')->get();
    }

    /**
     * @return Builder<Take>
     */
    public function builder(): Builder
    {
        return $this->constrain(Take::query());
    }

    /**
     * @param  Builder<Take>  $query
     * @return Builder<Take>
     */
    public function constrain(Builder $query): Builder
    {
        $now = CarbonImmutable::now();

        return $query->where(fn (Builder $stuck): Builder => $stuck
            ->where(fn (Builder $silent): Builder => $silent
                ->whereIn('status', [TakeStatus::Queued->value, TakeStatus::Processing->value])
                ->whereRaw('coalesce(last_event_at, submitted_at, created_at) < ?', [$now->subMinutes(self::SILENT_MINUTES)]))
            ->orWhere(fn (Builder $pending): Builder => $pending
                ->where('status', TakeStatus::Pending->value)
                ->where('created_at', '<', $now->subMinutes(self::PENDING_MINUTES))));
    }
}
