<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\DTOs\Projects\ProjectWorkspaceDTO;
use App\Enums\Takes\TakeFilter;
use App\Enums\Takes\TakeStatus;
use App\Models\Project;
use App\Models\Take;
use App\Queries\Takes\ListTakeNumbersQuery;

/**
 * The workspace grid (P04, spec 4): takes newest first, never re-sorted
 * live (2-02), `PAGE_SIZE` per page and "load more" by pages, filtered by
 * status group, with the counts per group from the same table.
 */
final class GetProjectWorkspaceQuery
{
    public const int PAGE_SIZE = 24;

    /** "Load more" stops here; older takes stay reachable from their links. */
    public const int MAX_PAGES = 20;

    public function __construct(
        private readonly GetProjectSpendQuery $spend,
        private readonly ListTakeNumbersQuery $numbers,
    ) {}

    public function execute(Project $project, TakeFilter $filter = TakeFilter::All, int $pages = 1): ProjectWorkspaceDTO
    {
        $pages = max(1, min($pages, self::MAX_PAGES));
        $limit = $pages * self::PAGE_SIZE;

        $takes = Take::query()
            ->where('takes.project_id', $project->id)
            ->whereIn('takes.status', $filter->statuses())
            ->select('takes.*')
            ->selectSub(ListTakeNumbersQuery::numberSubquery(), 'number')
            ->with('usageRecord')
            ->latest('takes.created_at')
            ->orderByDesc('takes.id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $takes->count() > $limit;
        $takes = $takes->take($limit)->values();

        // Remix numbers: the parents may be outside this page or filter.
        $parentIds = $takes->pluck('parent_take_id')->filter()->unique()->values()->all();
        $parentNumbers = $this->numbers->execute(array_values(array_map(strval(...), $parentIds)));

        foreach ($takes as $take) {
            if ($take->parent_take_id !== null) {
                $take->setAttribute('parent_number', $parentNumbers[$take->parent_take_id] ?? null);
            }
        }

        return new ProjectWorkspaceDTO(
            project: $project,
            takes: array_values($takes->all()),
            hasMore: $hasMore,
            counts: $this->counts($project),
            spend: $this->spend->execute([$project->id])[$project->id],
            filter: $filter,
            pages: $pages,
        );
    }

    /**
     * @return array<value-of<TakeFilter>, int>
     */
    private function counts(Project $project): array
    {
        /** @var array<string, int|string> $byStatus */
        $byStatus = Take::query()
            ->where('project_id', $project->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $count = fn (TakeFilter $filter): int => array_sum(array_map(
            fn (string $status): int => (int) ($byStatus[$status] ?? 0),
            $filter->statuses(),
        ));

        return [
            TakeFilter::All->value => $count(TakeFilter::All),
            TakeFilter::InProgress->value => $count(TakeFilter::InProgress),
            TakeFilter::Done->value => (int) ($byStatus[TakeStatus::Succeeded->value] ?? 0),
            TakeFilter::Failed->value => (int) ($byStatus[TakeStatus::Failed->value] ?? 0),
        ];
    }
}
