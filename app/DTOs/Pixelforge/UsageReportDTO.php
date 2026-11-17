<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Support\Money\Nanodollars;
use App\Support\Pixelforge\Payload;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * GET /v1/usage for the calling key: one row per UTC day, inclusive, days
 * without usage included as zeros. Longer ranges than the API allows are
 * fetched in chunks and combined with merge(). Totals are summed from the
 * exact per-day nanodollars (D-021), never taken from rounded values.
 *
 * @phpstan-import-type UsageDayPayload from UsageDayDTO
 *
 * @phpstan-type UsageReportPayload array{
 *     object: 'usage',
 *     start_date: string,
 *     end_date: string,
 *     data: list<UsageDayPayload>,
 *     total: array<string, int|string>,
 * }
 */
final readonly class UsageReportDTO
{
    /**
     * @param  list<UsageDayDTO>  $days  ascending by date
     */
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public array $days,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see UsageReportPayload}
     */
    public static function fromArray(array $data): self
    {
        $days = [];

        foreach (Payload::array($data, 'data') as $day) {
            if (! is_array($day)) {
                throw Payload::invalid('data', 'a list of usage days');
            }

            $days[] = UsageDayDTO::fromArray($day);
        }

        usort($days, fn (UsageDayDTO $a, UsageDayDTO $b): int => $a->date <=> $b->date);

        return new self(
            startDate: Payload::date($data, 'start_date'),
            endDate: Payload::date($data, 'end_date'),
            days: $days,
        );
    }

    /**
     * Joins consecutive chunk reports into one report over the whole range.
     *
     * @param  list<self>  $reports
     */
    public static function merge(array $reports): self
    {
        if ($reports === []) {
            throw new InvalidArgumentException('At least one usage report is needed to merge.');
        }

        usort($reports, fn (self $a, self $b): int => $a->startDate <=> $b->startDate);

        $days = [];
        $previous = null;

        foreach ($reports as $report) {
            if ($previous !== null && ! $report->startDate->equalTo($previous->endDate->addDay())) {
                throw new InvalidArgumentException('Usage reports to merge must cover consecutive date ranges.');
            }

            array_push($days, ...$report->days);
            $previous = $report;
        }

        return new self(
            startDate: $reports[0]->startDate,
            endDate: $reports[count($reports) - 1]->endDate,
            days: $days,
        );
    }

    public function totalCost(): Nanodollars
    {
        return array_reduce(
            $this->days,
            fn (Nanodollars $sum, UsageDayDTO $day): Nanodollars => $sum->plus($day->cost),
            Nanodollars::zero(),
        );
    }

    public function totalGenerations(): int
    {
        return array_sum(array_map(fn (UsageDayDTO $day): int => $day->generations, $this->days));
    }

    public function forDate(CarbonImmutable $date): ?UsageDayDTO
    {
        $key = $date->utc()->format('Y-m-d');

        foreach ($this->days as $day) {
            if ($day->date->format('Y-m-d') === $key) {
                return $day;
            }
        }

        return null;
    }
}
