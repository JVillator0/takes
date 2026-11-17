<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\DTOs\Projects\ProjectNameDTO;
use App\Models\Customer;
use App\Models\Project;

/**
 * A new, empty project for the customer (spec 4 issue 2, P03). The policy
 * (`projects.manage`) is checked by the request.
 */
final readonly class CreateProjectAction
{
    public function execute(Customer $customer, ProjectNameDTO $data): Project
    {
        $project = new Project;
        $project->forceFill([
            'customer_id' => $customer->id,
            'name' => $data->name,
        ])->save();

        return $project;
    }
}
