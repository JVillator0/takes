<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The provider cost we absorbed per UTC billing day: orphan successes
 * (D-025, D-077), whose usage stays voided and never reaches the ledger,
 * but which the provider still bills. With {@see GetSettledDailyCostsQuery}
 * it makes up our side of `billing:verify --usage` (issue #102, D-058).
 */
final readonly class GetAbsorbedDailyCostsQuery
{
    /** @return array<string, Nanodollars> keyed by 'Y-m-d' */
    public function execute(Provider $provider, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('usage_records')
            ->where('provider', $provider->value)
            ->where('status', UsageStatus::Voided->value)
            ->whereNotNull('absorbed_cost_nanos')
            ->whereBetween('billing_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('billing_date')
            ->orderBy('billing_date')
            ->selectRaw('billing_date, sum(absorbed_cost_nanos) as cost')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->billing_date => Nanodollars::fromInt((string) $row->cost)])
            ->all();
    }
}
