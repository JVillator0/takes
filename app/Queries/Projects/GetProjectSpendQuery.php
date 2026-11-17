<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Enums\Billing\UsageStatus;
use App\Models\Project;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;

/**
 * What a customer spent in a project (P03, 2-02): the settled charges of its
 * takes and of its AI suggestions, the same ledger the billing page reads.
 * A SQL sum through Nanodollars::fromInt (M-04); holds are not spend.
 */
final class GetProjectSpendQuery
{
    /**
     * @param  list<string>  $projectIds
     * @return array<string, Nanodollars> project id ⇒ spend
     */
    public function execute(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $takes = UsageRecord::query()
            ->join('takes', fn ($join) => $join
                ->on('takes.id', '=', 'usage_records.billable_id')
                ->where('usage_records.billable_type', (new Take)->getMorphClass()))
            ->whereIn('takes.project_id', $projectIds)
            ->where('usage_records.status', UsageStatus::Settled->value)
            ->selectRaw('takes.project_id as project_id, sum(usage_records.charge_nanos) as spend')
            ->groupBy('takes.project_id');

        $suggestions = UsageRecord::query()
            ->join('suggestions', fn ($join) => $join
                ->on('suggestions.id', '=', 'usage_records.billable_id')
                ->where('usage_records.billable_type', (new Suggestion)->getMorphClass()))
            ->whereIn('suggestions.project_id', $projectIds)
            ->where('usage_records.status', UsageStatus::Settled->value)
            ->selectRaw('suggestions.project_id as project_id, sum(usage_records.charge_nanos) as spend')
            ->groupBy('suggestions.project_id');

        $spend = array_fill_keys($projectIds, Nanodollars::zero());

        foreach ([$takes, $suggestions] as $query) {
            foreach ($query->toBase()->get() as $row) {
                $projectId = (string) $row->project_id;
                $spend[$projectId] = ($spend[$projectId] ?? Nanodollars::zero())->plus(Nanodollars::fromInt((string) $row->spend));
            }
        }

        return $spend;
    }
}
