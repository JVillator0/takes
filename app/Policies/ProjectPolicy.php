<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Models\Project;
use App\Policies\Concerns\AuthorizesCustomersOnly;
use Illuminate\Auth\Access\Response;

/**
 * Projects: `projects.manage` plus ownership (spec 3-01). Another customer's
 * project is a 404, never a 403 (spec 2-02).
 */
final readonly class ProjectPolicy
{
    use AuthorizesCustomersOnly;

    public function viewAny(Customer $customer): Response
    {
        return $this->canManage($customer);
    }

    public function view(Customer $customer, Project $project): Response
    {
        return $this->owns($customer, $project) ?? $this->canManage($customer);
    }

    public function create(Customer $customer): Response
    {
        return $this->canManage($customer);
    }

    /** Rename and archive (projects are never hard-deleted while takes reference them). */
    public function update(Customer $customer, Project $project): Response
    {
        return $this->owns($customer, $project) ?? $this->canManage($customer);
    }

    private function canManage(Customer $customer): Response
    {
        return $customer->checkPermissionTo(CustomerPermission::ProjectsManage->value)
            ? Response::allow()
            : DenialReason::AccountRestricted->deny();
    }

    /** A denial when the customer does not own the project, null when they do. */
    private function owns(Customer $customer, Project $project): ?Response
    {
        return $project->customer_id === $customer->id ? null : Response::denyAsNotFound();
    }
}
