<?php

declare(strict_types=1);

namespace App\Queries\Customers;

use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\Customer;
use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Customers with their money in one query (A09/A05): take counts (all and failed) and lifetime spend and provider
 * cost from settled usage records (spec 7 Definitions) as SQL sums; balance and held are the cached columns
 * (D-024). Profit = spend − cost.
 */
final class ListCustomersWithMoneyQuery
{
    /**
     * @return Collection<int, Customer>
     */
    public function execute(): Collection
    {
        return $this->builder()->get();
    }

    /**
     * @return Builder<Customer>
     */
    public function builder(): Builder
    {
        return Customer::query()
            ->select('customers.*')
            ->withCount([
                'takes',
                'takes as failed_takes_count' => fn (Builder $takes): Builder => $takes->where('status', TakeStatus::Failed->value),
            ])
            ->selectSub($this->settled()->selectRaw('coalesce(sum(charge_nanos), 0)'), 'spend_nanos')
            ->selectSub($this->settled()->selectRaw('coalesce(sum(provider_cost_nanos), 0)'), 'cost_nanos');
    }

    /**
     * @return Builder<UsageRecord>
     */
    private function settled(): Builder
    {
        return UsageRecord::query()
            ->whereColumn('usage_records.customer_id', 'customers.id')
            ->where('status', UsageStatus::Settled->value);
    }
}
