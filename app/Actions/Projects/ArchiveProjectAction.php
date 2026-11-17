<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;

/**
 * Archives a project (spec 4 issue 2): it leaves the projects list but is
 * never deleted, because its takes and their money rows reference it
 * (restrict FKs, spec 3-02). Archiving twice keeps the first date.
 */
final readonly class ArchiveProjectAction
{
    public function execute(Project $project): Project
    {
        if ($project->archived_at === null) {
            $project->forceFill(['archived_at' => now()])->save();
        }

        return $project;
    }
}
