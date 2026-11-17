<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Our provider cost per UTC billing day (settled usages only), the side of
 * `billing:verify --usage` that comes from the ledger.
 */
final readonly class GetSettledDailyCostsQuery
{
    /** @return array<string, Nanodollars> keyed by 'Y-m-d' */
    public function execute(Provider $provider, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('usage_records')
            ->where('provider', $provider->value)
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('billing_date')
            ->orderBy('billing_date')
            ->selectRaw('billing_date, sum(provider_cost_nanos) as cost')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->billing_date => Nanodollars::fromInt((string) $row->cost)])
            ->all();
    }
}
