<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\DailySpendCostDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * Settled spend per provider, total spend and provider cost per UTC billing day, `from`..`until` inclusive and
 * oldest first, for the dashboard's "Spend, cost and profit" chart. One `GROUP BY billing_date` with a filtered
 * sum per provider, so every total is a SQL sum (FI-13); days without settled usage are filled with zeros.
 */
final readonly class ListDailySpendCostQuery
{
    /**
     * @return list<DailySpendCostDTO>
     */
    public function execute(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $query = UsageRecord::query()
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$from->toDateString(), $until->toDateString()])
            ->groupBy('billing_date')
            ->toBase()
            ->selectRaw('billing_date')
            ->selectRaw('sum(charge_nanos) as spend_nanos')
            ->selectRaw('sum(provider_cost_nanos) as cost_nanos');

        foreach (Provider::cases() as $provider) {
            $query->selectRaw('coalesce(sum(charge_nanos) filter (where provider = ?), 0) as '.self::column($provider), [$provider->value]);
        }

        $rows = $query->get()->keyBy(fn (object $row): string => substr((string) $row->billing_date, 0, 10));

        $days = [];

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($until); $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = $rows->get($date);
            $spend = Nanodollars::fromInt($row->spend_nanos ?? 0);
            $cost = Nanodollars::fromInt($row->cost_nanos ?? 0);

            $days[] = DailySpendCostDTO::fromArray([
                'date' => $date,
                'spend_by_provider' => array_combine(
                    array_map(fn (Provider $provider): string => $provider->value, Provider::cases()),
                    array_map(fn (Provider $provider): Nanodollars => Nanodollars::fromInt($row->{self::column($provider)} ?? 0), Provider::cases()),
                ),
                'spend' => $spend,
                'cost' => $cost,
                'profit' => $spend->minus($cost),
            ]);
        }

        return $days;
    }

    /**
     * @return literal-string
     */
    private static function column(Provider $provider): string
    {
        return match ($provider) {
            Provider::Pixelforge => 'spend_pixelforge_nanos',
            Provider::Anthropic => 'spend_anthropic_nanos',
        };
    }
}
