<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;

/**
 * One of the customer's takes by id, optionally within a project, or null.
 * Callers answer null with the same 404 whether the take is unknown or
 * belongs to someone else (2-02: no existence leak, never 403).
 */
final class GetCustomerTakeQuery
{
    public function execute(Customer $customer, string $takeId, ?Project $project = null): ?Take
    {
        return Take::query()
            ->whereKey($takeId)
            ->where('customer_id', $customer->id)
            ->when($project !== null, fn ($query) => $query->where('project_id', $project?->id))
            ->first();
    }
}
