<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\MoneyTotalsDTO;
use App\Enums\Billing\UsageStatus;
use App\Models\UsageRecord;
use App\Support\Money\BasisPoints;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * Spend, provider cost, profit and margin over UTC billing days `from`..`until` (inclusive), all providers (spec 7
 * Definitions): one SQL sum over settled usage records, cast through Nanodollars::fromInt (M-04). Purchases are
 * prepaid cash and never part of spend. The dashboard's money stats read today with it.
 */
final readonly class GetMoneyTotalsQuery
{
    public function execute(CarbonImmutable $from, CarbonImmutable $until): MoneyTotalsDTO
    {
        $row = UsageRecord::query()
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$from->toDateString(), $until->toDateString()])
            ->toBase()
            ->selectRaw('coalesce(sum(charge_nanos), 0) as spend_nanos')
            ->selectRaw('coalesce(sum(provider_cost_nanos), 0) as cost_nanos')
            ->first();

        $spend = Nanodollars::fromInt($row->spend_nanos ?? 0);
        $cost = Nanodollars::fromInt($row->cost_nanos ?? 0);
        $profit = $spend->minus($cost);

        return MoneyTotalsDTO::fromArray([
            'spend' => $spend,
            'cost' => $cost,
            'profit' => $profit,
            'margin_bps' => BasisPoints::ratio($profit->toInt(), $spend->toInt()),
        ]);
    }
}
