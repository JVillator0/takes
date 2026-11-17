<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Models\Customer;
use App\Models\Take;

/**
 * Takes created on the current UTC day, for one customer or for everyone
 * (D-050 caps: `daily_take_cap`, `demo_quota_reached`). Every take counts,
 * whatever its outcome: the caps bound attempts, not spend.
 */
final class GetDailyTakeCountQuery
{
    public function execute(?Customer $customer = null): int
    {
        $today = now()->utc()->startOfDay();

        return Take::query()
            ->when($customer !== null, fn ($query) => $query->where('customer_id', $customer?->id))
            ->where('created_at', '>=', $today)
            ->where('created_at', '<', $today->addDay())
            ->count();
    }
}
