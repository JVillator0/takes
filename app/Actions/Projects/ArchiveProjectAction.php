<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Customer;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Archives a project (spec 4 issue 2): it leaves the projects list but is
 * never deleted, because its takes and their money rows reference it
 * (restrict FKs, spec 3-02). Archiving twice keeps the first date.
 *
 * Under the customer lock, the one CreateTakeAction holds while it checks
 * the project, so a take is never created in a project archived meanwhile.
 */
final readonly class ArchiveProjectAction
{
    public function execute(Project $project): Project
    {
        return DB::transaction(function () use ($project): Project {
            Customer::query()->lockForUpdate()->findOrFail($project->customer_id);
            $project->refresh();

            if ($project->archived_at === null) {
                $project->forceFill(['archived_at' => now()])->save();
            }

            return $project;
        });
    }
}
