<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Enums\Takes\TakeStatus;
use App\Models\Take;

/** The newest succeeded take of each project: its image is the project's cover (P03). */
final class ListProjectCoversQuery
{
    /**
     * @param  list<string>  $projectIds
     * @return array<string, Take> project id ⇒ take
     */
    public function execute(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        /** @var array<string, Take> */
        return Take::query()
            ->fromSub(
                Take::query()
                    ->whereIn('project_id', $projectIds)
                    ->where('status', TakeStatus::Succeeded->value)
                    ->selectRaw('takes.*, row_number() over (partition by project_id order by created_at desc, id desc) as cover_rank'),
                'takes',
            )
            ->where('cover_rank', 1)
            ->get()
            ->keyBy('project_id')
            ->all();
    }
}
