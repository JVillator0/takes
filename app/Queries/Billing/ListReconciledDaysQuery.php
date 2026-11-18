<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Enums\Billing\ReconciliationStatus;
use Carbon\CarbonImmutable;

/**
 * The days of a Billing › Reports range that are **reconciled** (spec 7, #141): the last cached Pixelforge
 * `/v1/usage` check for the day (Providers › Pixelforge usage) matches our settled Pixelforge usage, with the same
 * rules as that page (closed days only, no take in flight, figures fetched after the day ended). Reads the cache
 * and the database only; it never calls Pixelforge.
 */
final readonly class ListReconciledDaysQuery
{
    public function __construct(private GetPixelforgeReconciliationQuery $reconciliation) {}

    /**
     * @return list<string> 'Y-m-d' dates, ascending
     */
    public function execute(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $reconciled = [];

        // The usage check judges at most 92 days at a time.
        for ($start = $from; $start->lessThanOrEqualTo($until); $start = $start->addDays(GetPixelforgeReconciliationQuery::MAX_DAYS)) {
            $end = $start->addDays(GetPixelforgeReconciliationQuery::MAX_DAYS - 1);
            $end = $end->greaterThan($until) ? $until : $end;

            foreach ($this->reconciliation->execute($start, $end, cachedOnly: true)->days as $day) {
                if ($day->status === ReconciliationStatus::Match) {
                    $reconciled[] = $day->date;
                }
            }
        }

        sort($reconciled);

        return $reconciled;
    }
}
