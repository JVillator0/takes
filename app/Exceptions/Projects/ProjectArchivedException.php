<?php

declare(strict_types=1);

namespace App\Exceptions\Projects;

use RuntimeException;

/**
 * A take was requested in an archived project (spec 4 issue 2): archived
 * projects leave the list and their workspace is a 404, so a take there
 * could never be seen, opened or cancelled. Checked by CreateTakeAction under
 * the customer lock, which ArchiveProjectAction takes too, so a composer
 * submit or an AI apply racing the archive cannot slip through.
 */
final class ProjectArchivedException extends RuntimeException
{
    public const string CODE = 'project_archived';

    public static function for(string $projectId): self
    {
        return new self("Project {$projectId} is archived: no take can be added to it.");
    }
}
