<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use UnexpectedValueException;

/**
 * Today's global Anthropic provider cost (spec 3-01, `ai_paused`): settled
 * usages billed today at their provider cost, plus open usages created today
 * at their `max_cost_nanos` (the worst case still in flight). Voided usages
 * cost nothing.
 */
final class GetDailyAiSpendQuery
{
    public function execute(): Nanodollars
    {
        $today = now()->utc()->startOfDay();

        $settled = UsageRecord::query()
            ->where('provider', Provider::Anthropic->value)
            ->where('status', UsageStatus::Settled->value)
            ->whereDate('billing_date', $today->toDateString())
            ->sum('provider_cost_nanos');

        $open = UsageRecord::query()
            ->where('provider', Provider::Anthropic->value)
            ->where('status', UsageStatus::Open->value)
            ->where('created_at', '>=', $today)
            ->where('created_at', '<', $today->addDay())
            ->sum('max_cost_nanos');

        return Nanodollars::fromInt(self::integer($settled))->plus(Nanodollars::fromInt(self::integer($open)));
    }

    /** Postgres `sum(bigint)` is numeric: PDO returns an integer string (money rule M-04). */
    private static function integer(mixed $sum): int|string
    {
        return is_int($sum) || is_string($sum)
            ? $sum
            : throw new UnexpectedValueException('A nanodollar sum must be an integer or an integer string.');
    }
}
