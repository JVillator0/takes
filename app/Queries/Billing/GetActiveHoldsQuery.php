<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\ActiveHoldsDTO;
use App\Enums\Billing\UsageStatus;
use App\Models\Customer;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;

/**
 * Active holds for the dashboard (spec 7 Definitions): Σ `customers.held_nanos`, cast through
 * Nanodollars::fromInt (M-04), and the count of open usage records, read through their partial index
 * (`usage_records_open_customer_id_index`). Nothing else: the dashboard card needs only these two figures.
 */
final readonly class GetActiveHoldsQuery
{
    public function execute(): ActiveHoldsDTO
    {
        $held = Customer::query()->toBase()->selectRaw('coalesce(sum(held_nanos), 0) as held_nanos')->value('held_nanos');

        return ActiveHoldsDTO::fromArray([
            'held' => Nanodollars::fromInt(is_int($held) || is_string($held) ? $held : 0),
            'open_usage_records' => UsageRecord::query()->where('status', UsageStatus::Open->value)->count(),
        ]);
    }
}
