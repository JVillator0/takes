<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use Carbon\CarbonImmutable;

/**
 * How many takes were created since a moment, per status (spec 7 Definitions: takes created in range, all
 * statuses): one `GROUP BY status`. Every status is present, zero when none. The dashboard reads today's
 * succeeded and failed takes and the "Takes by status" chart from it.
 */
final readonly class ListTakeStatusCountsQuery
{
    /**
     * @return array<string, int> keyed by TakeStatus value, in TakeStatus::cases() order
     */
    public function execute(CarbonImmutable $since): array
    {
        $counts = Take::query()
            ->where('created_at', '>=', $since)
            ->groupBy('status')
            ->toBase()
            ->selectRaw('status, count(*) as takes')
            ->pluck('takes', 'status');

        $result = [];

        foreach (TakeStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts->get($status->value) ?? 0);
        }

        return $result;
    }
}
