<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use UnexpectedValueException;

/**
 * Today's global Anthropic provider cost (spec 3-01 and spec 8, `ai_paused`):
 * settled usages billed today at their provider cost, plus voided usages
 * whose call the provider still billed (refusal, `max_tokens`, invalid
 * output, a result after expiry) at their `absorbed_cost_nanos`, completed
 * today, plus every open usage at its `max_cost_nanos` (currently held,
 * whenever it was opened: the worst case still in flight). A voided usage
 * with no provider work (an error before any token, an expiry) costs nothing.
 */
final class GetDailyAiSpendQuery
{
    public function execute(): Nanodollars
    {
        $today = now()->utc()->startOfDay();

        $settled = UsageRecord::query()
            ->where('provider', Provider::Anthropic->value)
            ->where('status', UsageStatus::Settled->value)
            ->where('billing_date', $today->toDateString())
            ->sum('provider_cost_nanos');

        // Free for the customer (M-13), billed to us: completed_at is the
        // call's UTC instant (a voided usage has no billing_date).
        $absorbed = UsageRecord::query()
            ->where('provider', Provider::Anthropic->value)
            ->where('status', UsageStatus::Voided->value)
            ->where('completed_at', '>=', $today)
            ->where('completed_at', '<', $today->addDay())
            ->sum('absorbed_cost_nanos');

        $open = UsageRecord::query()
            ->where('provider', Provider::Anthropic->value)
            ->where('status', UsageStatus::Open->value)
            ->sum('max_cost_nanos');

        return Nanodollars::fromInt(self::integer($settled))
            ->plus(Nanodollars::fromInt(self::integer($absorbed)))
            ->plus(Nanodollars::fromInt(self::integer($open)));
    }

    /** Postgres `sum(bigint)` is numeric: PDO returns an integer string (money rule M-04). */
    private static function integer(mixed $sum): int|string
    {
        return is_int($sum) || is_string($sum)
            ? $sum
            : throw new UnexpectedValueException('A nanodollar sum must be an integer or an integer string.');
    }
}
