<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * Settled provider cost per provider over UTC billing days `from`..`until` inclusive (the dashboard's provider
 * cost split): one SQL sum per provider, every provider present (zero when it had no settled usage).
 */
final readonly class ListProviderCostSplitQuery
{
    /**
     * @return array<string, Nanodollars> keyed by Provider value, in Provider::cases() order
     */
    public function execute(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $costs = UsageRecord::query()
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$from->toDateString(), $until->toDateString()])
            ->groupBy('provider')
            ->toBase()
            ->selectRaw('provider, sum(provider_cost_nanos) as cost_nanos')
            ->pluck('cost_nanos', 'provider');

        $split = [];

        foreach (Provider::cases() as $provider) {
            $cost = $costs->get($provider->value);
            $split[$provider->value] = Nanodollars::fromInt(is_int($cost) || is_string($cost) ? $cost : 0);
        }

        return $split;
    }
}
