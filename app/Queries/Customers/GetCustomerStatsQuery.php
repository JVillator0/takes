<?php

declare(strict_types=1);

namespace App\Queries\Customers;

use App\DTOs\Customers\CustomerStatsDTO;
use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * One aggregate over `customers` for the Customers header (A09).
 */
final readonly class GetCustomerStatsQuery
{
    public function execute(): CustomerStatsDTO
    {
        $row = Customer::query()
            ->toBase()
            ->selectRaw('count(*) as customers')
            ->selectRaw('count(*) filter (where last_active_at >= ?) as active_today', [CarbonImmutable::now('UTC')->startOfDay()])
            ->selectRaw('coalesce(sum(balance_nanos), 0) as outstanding_nanos')
            ->selectRaw('coalesce(sum(held_nanos), 0) as holds_nanos')
            ->first();

        return CustomerStatsDTO::fromArray([
            'customers' => (int) ($row->customers ?? 0),
            'active_today' => (int) ($row->active_today ?? 0),
            'outstanding_nanos' => $row->outstanding_nanos ?? 0,
            'holds_nanos' => $row->holds_nanos ?? 0,
        ]);
    }
}
