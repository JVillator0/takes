<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Enums\Takes\TakeStatus;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The customer's projects (P03), most recently used first: archived ones
 * are left out. Each carries `takes_count`, `in_progress_count` (the same
 * In progress definition as the grid, 2-02) and `last_take_at`; the cover
 * take and the spend come from their own queries.
 */
final class ListProjectsQuery
{
    /**
     * @return Collection<int, Project>
     */
    public function execute(Customer $customer, ?string $search = null): Collection
    {
        $nonTerminal = array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal());
        $search = $search !== null ? trim($search) : '';

        return Project::query()
            ->where('customer_id', $customer->id)
            ->whereNull('archived_at')
            ->when($search !== '', fn (Builder $query) => $query->whereLike('name', '%'.addcslashes($search, '%_\\').'%'))
            ->withCount([
                'takes',
                'takes as in_progress_count' => fn (Builder $query) => $query->whereIn('status', $nonTerminal),
            ])
            ->withMax('takes as last_take_at', 'created_at')
            ->orderByRaw('greatest(projects.updated_at, coalesce((select max(takes.created_at) from takes where takes.project_id = projects.id), projects.updated_at)) desc')
            ->orderByDesc('projects.id')
            ->get();
    }
}
