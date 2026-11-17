<?php

declare(strict_types=1);

namespace App\Queries\Suggestions;

use App\Models\Customer;
use App\Models\Suggestion;

/**
 * A customer's "Describe a change" requests on the current UTC day
 * (`ai_daily_limit`, D-050).
 */
final class GetDailySuggestionCountQuery
{
    public function execute(Customer $customer): int
    {
        $today = now()->utc()->startOfDay();

        return Suggestion::query()
            ->where('customer_id', $customer->id)
            ->where('created_at', '>=', $today)
            ->where('created_at', '<', $today->addDay())
            ->count();
    }
}
