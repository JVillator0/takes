<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Enums\Billing\UsageStatus;
use App\Models\Project;
use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Projects with their take count and spend (A09) in one query: spend = Σ settled `charge_nanos` of the usage
 * records billed for the project's takes (spec 7 Definitions), as an integer string from SQL.
 */
final class ListProjectsWithSpendQuery
{
    /**
     * @return Collection<int, Project>
     */
    public function execute(): Collection
    {
        return $this->builder()->latest('updated_at')->get();
    }

    /**
     * @return Builder<Project>
     */
    public function builder(): Builder
    {
        $spend = UsageRecord::query()
            ->selectRaw('coalesce(sum(usage_records.charge_nanos), 0)')
            ->join('takes', 'takes.id', '=', 'usage_records.billable_id')
            ->where('usage_records.billable_type', 'take')
            ->where('usage_records.status', UsageStatus::Settled->value)
            ->whereColumn('takes.project_id', 'projects.id');

        return Project::query()
            ->select('projects.*')
            ->withCount('takes')
            ->selectSub($spend, 'spend_nanos');
    }
}
