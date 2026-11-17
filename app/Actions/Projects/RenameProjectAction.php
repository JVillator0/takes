<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\DTOs\Projects\ProjectNameDTO;
use App\Models\Project;

/** Renames a project (spec 4 issue 2). Ownership and `projects.manage` are checked by the request. */
final readonly class RenameProjectAction
{
    public function execute(Project $project, ProjectNameDTO $data): Project
    {
        $project->forceFill(['name' => $data->name])->save();

        return $project;
    }
}
