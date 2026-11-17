<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Models\Take;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The number customers see on a take ("Take #10", "Remix of #8"): its
 * position in its project, in creation order, starting at 1. Take ids are
 * ulids; this keeps them out of the copy without storing a counter.
 */
final class ListTakeNumbersQuery
{
    /**
     * @param  list<string>  $takeIds
     * @return array<string, int> take id ⇒ number
     */
    public function execute(array $takeIds): array
    {
        if ($takeIds === []) {
            return [];
        }

        /** @var array<string, int|string> $numbers */
        $numbers = Take::query()
            ->whereKey(array_values(array_unique($takeIds)))
            ->select('takes.id')
            ->selectSub(self::numberSubquery(), 'number')
            ->pluck('number', 'id')
            ->all();

        return array_map(intval(...), $numbers);
    }

    /**
     * Correlated count for a `takes` row in the outer query: how many takes
     * of the same project were created before it or with it (ties broken by
     * the ulid). Used by the grid query too.
     *
     * @return Builder<Take>
     */
    public static function numberSubquery(): Builder
    {
        return Take::query()
            ->from('takes', 'numbered')
            ->selectRaw('count(*)')
            ->whereColumn('numbered.project_id', 'takes.project_id')
            ->where(fn (QueryBuilder|Builder $query) => $query
                ->whereColumn('numbered.created_at', '<', 'takes.created_at')
                ->orWhere(fn (QueryBuilder|Builder $tie) => $tie
                    ->whereColumn('numbered.created_at', 'takes.created_at')
                    ->whereColumn('numbered.id', '<=', 'takes.id')));
    }
}
