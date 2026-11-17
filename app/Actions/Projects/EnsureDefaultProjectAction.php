<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\DTOs\Projects\ProjectNameDTO;
use App\Enums\Auth\CustomerPermission;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * P03 decision 1: the first visit creates "Untitled project", so the
 * composer is never blocked behind a form. Only for a customer with no
 * project at all (archived ones count) who may manage projects; the
 * read-only showcase login (D-072) never gets one.
 */
final readonly class EnsureDefaultProjectAction
{
    public function __construct(private CreateProjectAction $createProject) {}

    public function execute(Customer $customer): ?Project
    {
        if (! $customer->checkPermissionTo(CustomerPermission::ProjectsManage->value)) {
            return null;
        }

        // Two first visits at once (a prefetch and the visit, two tabs)
        // serialize on the customer row, so only one creates the project.
        return DB::transaction(function () use ($customer): ?Project {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if (Project::query()->where('customer_id', $customer->id)->exists()) {
                return null;
            }

            return $this->createProject->execute($customer, new ProjectNameDTO(__('takes.projects.untitled')));
        });
    }
}
