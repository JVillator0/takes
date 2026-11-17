<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\PixelforgeReconciliationDTO;
use App\DTOs\Billing\ReconciliationDayDTO;
use App\DTOs\Billing\UsageTotalsDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\ReconciliationStatus;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Pixelforge\AdminRequestBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Providers › Pixelforge usage (A04, D-034): per UTC billing day, our settled Pixelforge usage (generations, the
 * four token sums, cost) against `GET /v1/usage`, cached 5 minutes per range. A day is Pending while takes created
 * that day are still in flight, and today always is ("N in flight"), never Mismatch. When Pixelforge cannot be
 * reached each closed day falls back to its last good figures, marked unavailable. Today and later days are
 * Pending: never judged, never kept as last-good, and figures fetched before a day ended are never judged for it.
 * Every /v1/usage call spends one request of the admin budget (AdminRequestBudget); when the budget is spent or
 * a call failed in the last minute, the last good figures are served instead.
 *
 * Known limit: in-flight takes hold the day they were created, while billing uses the completion day; a take
 * created just before midnight that is still in flight locally holds the earlier day, not the next.
 */
final readonly class GetPixelforgeReconciliationQuery
{
    public const int MAX_DAYS = 92;

    public const int CACHE_SECONDS = 300;

    /** Set for a minute after a failed /v1/usage call; while set, the page serves the last good figures. */
    public const string DOWN_KEY = 'admin:pixelforge-usage:down';

    public const int DOWN_SECONDS = 60;

    /** How long a day's last good figures back the page when Pixelforge cannot be reached. */
    public const int LAST_GOOD_SECONDS = 30 * 24 * 3600;

    public function __construct(private PixelforgeClient $client) {}

    public function execute(CarbonImmutable $from, CarbonImmutable $until, bool $refresh = false): PixelforgeReconciliationDTO
    {
        $from = $from->utc()->startOfDay();
        $until = $until->utc()->startOfDay();

        if ($until->lessThan($from) || $from->diffInDays($until) >= self::MAX_DAYS) {
            throw new InvalidArgumentException('The usage range must be ordered and at most 92 days.');
        }

        $ours = $this->ours($from, $until);
        $inFlight = $this->inFlight($from, $until);
        [$theirs, $fetchedAt, $unavailable, $throttled] = $this->theirs($from, $until, $refresh);
        $today = CarbonImmutable::now('UTC')->toDateString();

        $days = [];
        $oursTotal = UsageTotalsDTO::zero();
        $theirsTotal = UsageTotalsDTO::zero();
        $missingTheirs = false;
        $matched = 0;
        $judged = 0;

        for ($day = $until; $day->greaterThanOrEqualTo($from); $day = $day->subDay()) {
            $date = $day->toDateString();
            $ourDay = $ours[$date] ?? UsageTotalsDTO::zero();
            $theirDay = $theirs[$date] ?? null;
            $flying = $inFlight[$date] ?? 0;

            $status = match (true) {
                // Today and later are still open: never judged (D-034).
                $date >= $today => ReconciliationStatus::Pending,
                $theirDay === null => ReconciliationStatus::Unavailable,
                $flying > 0 => ReconciliationStatus::Pending,
                $ourDay->equals($theirDay) => ReconciliationStatus::Match,
                default => ReconciliationStatus::Mismatch,
            };

            if ($status === ReconciliationStatus::Match || $status === ReconciliationStatus::Mismatch) {
                $judged++;
                $matched += $status === ReconciliationStatus::Match ? 1 : 0;
            }

            $oursTotal = $oursTotal->plus($ourDay);
            $missingTheirs = $missingTheirs || $theirDay === null;
            $theirsTotal = $theirDay === null ? $theirsTotal : $theirsTotal->plus($theirDay);
            $days[] = new ReconciliationDayDTO($date, $ourDay, $theirDay, $flying, $status);
        }

        // A total over a different set of days than ours would be misleading.
        return new PixelforgeReconciliationDTO($days, $oursTotal, $missingTheirs ? null : $theirsTotal, $matched, $judged, $fetchedAt, $unavailable, $throttled);
    }

    /**
     * Settled Pixelforge usage by billing day, one GROUP BY.
     *
     * @return array<string, UsageTotalsDTO>
     */
    private function ours(CarbonImmutable $from, CarbonImmutable $until): array
    {
        return UsageRecord::query()
            ->toBase()
            ->where('provider', Provider::Pixelforge->value)
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$from->toDateString(), $until->toDateString()])
            ->groupBy('billing_date')
            ->selectRaw('billing_date::text as day, count(*) as generations')
            ->selectRaw('coalesce(sum(input_text_tokens), 0) as input_text_tokens, coalesce(sum(input_image_tokens), 0) as input_image_tokens')
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(reasoning_tokens), 0) as reasoning_tokens')
            ->selectRaw('coalesce(sum(provider_cost_nanos), 0) as cost_nanos')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->day => UsageTotalsDTO::fromArray([
                'generations' => $row->generations,
                'input_text_tokens' => $row->input_text_tokens,
                'input_image_tokens' => $row->input_image_tokens,
                'output_tokens' => $row->output_tokens,
                'reasoning_tokens' => $row->reasoning_tokens,
                'cost_nanos' => $row->cost_nanos,
            ])])
            ->all();
    }

    /**
     * Takes still waiting on Pixelforge, by the UTC day they were created.
     *
     * @return array<string, int>
     */
    private function inFlight(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $day = "(created_at at time zone 'UTC')::date::text";

        return Take::query()
            ->toBase()
            ->whereIn('status', array_map(fn (TakeStatus $status): string => $status->value, TakeStatus::nonTerminal()))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until->addDay())
            ->groupByRaw($day)
            ->selectRaw("{$day} as day, count(*) as takes")
            ->pluck('takes', 'day')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Pixelforge's figures per UTC day: each day is cached 5 minutes under its own key (so the dashboard row and the
     * Reports badge can read a day's last check), plus a 30-day "last good" copy used when Pixelforge cannot be
     * reached. A day with neither is Unavailable.
     *
     * @return array{0: array<string, UsageTotalsDTO>, 1: CarbonImmutable|null, 2: bool, 3: bool} figures, fetched at, unavailable, throttled
     */
    private function theirs(CarbonImmutable $from, CarbonImmutable $until, bool $refresh): array
    {
        $dates = [];

        for ($day = $from; $day->lessThanOrEqualTo($until); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        if ($refresh) {
            foreach ($dates as $date) {
                Cache::forget(self::dayKey($date));
            }
        }

        $fresh = array_filter(array_combine($dates, array_map(fn (string $date): mixed => Cache::get(self::dayKey($date)), $dates)), self::isFinalOrOpen(...), ARRAY_FILTER_USE_BOTH);

        if (count($fresh) === count($dates)) {
            return [...$this->decode($fresh), false, false];
        }

        $fallback = function () use ($dates, $fresh): array {
            $last = array_filter(array_combine($dates, array_map(fn (string $date): mixed => Cache::get(self::lastKey($date)), $dates)), self::isFinalOrOpen(...), ARRAY_FILTER_USE_BOTH);

            return $this->decode($fresh + $last);
        };

        // A failed fetch is remembered for a minute, so an outage is not retried on every round-trip.
        if (Cache::has(self::DOWN_KEY)) {
            return [...$fallback(), true, false];
        }

        if (! AdminRequestBudget::reserve(1)) {
            return [...$fallback(), true, true];
        }

        try {
            $report = $this->client->getUsage($from, $until);
        } catch (PixelforgeException|PixelforgeNotConfigured) {
            Cache::put(self::DOWN_KEY, true, self::DOWN_SECONDS);

            return [...$fallback(), true, false];
        }

        $fetchedAt = CarbonImmutable::now()->toIso8601String();
        $fetched = [];

        foreach ($dates as $date) {
            $fetched[$date] = ['fetched_at' => $fetchedAt, 'totals' => UsageTotalsDTO::zero()->toArray()];
        }

        foreach ($report->days as $day) {
            $fetched[$day->date->toDateString()] = ['fetched_at' => $fetchedAt, 'totals' => (new UsageTotalsDTO(
                $day->generations,
                $day->inputTextTokens,
                $day->inputImageTokens,
                $day->outputTokens,
                $day->reasoningTokens,
                $day->cost,
            ))->toArray()];
        }

        $today = CarbonImmutable::now('UTC')->toDateString();

        foreach ($fetched as $date => $entry) {
            Cache::put(self::dayKey($date), $entry, self::CACHE_SECONDS);

            // Only a closed day's figures are final; an open day is never kept as last-good.
            if ($date < $today) {
                Cache::put(self::lastKey($date), $entry, self::LAST_GOOD_SECONDS);
            }
        }

        return [...$this->decode($fetched), false, false];
    }

    /**
     * A cached entry can stand for a closed day only if it was fetched after that day ended (UTC); figures fetched
     * while the day was open are partial. An open day (today or later) takes any fresh entry, since it is not judged.
     */
    private static function isFinalOrOpen(mixed $entry, string $date): bool
    {
        if (! is_array($entry) || ! is_string($entry['fetched_at'] ?? null)) {
            return false;
        }

        $dayEnd = CarbonImmutable::parse($date, 'UTC')->addDay();

        return $dayEnd->greaterThan(CarbonImmutable::now('UTC')) || CarbonImmutable::parse($entry['fetched_at'])->greaterThanOrEqualTo($dayEnd);
    }

    public static function dayKey(string $date): string
    {
        return 'admin:pixelforge-usage:day:'.$date;
    }

    private static function lastKey(string $date): string
    {
        return 'admin:pixelforge-usage:last:'.$date;
    }

    /**
     * @param  array<mixed>  $entries  date => {fetched_at, totals}
     * @return array{0: array<string, UsageTotalsDTO>, 1: CarbonImmutable|null}
     */
    private function decode(array $entries): array
    {
        $days = [];
        $oldest = null;

        foreach ($entries as $date => $entry) {
            if (! is_array($entry) || ! is_array($entry['totals'] ?? null) || ! is_string($entry['fetched_at'] ?? null)) {
                continue;
            }

            /** @var array{generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_nanos: int} $totals */
            $totals = $entry['totals'];
            $days[(string) $date] = UsageTotalsDTO::fromArray($totals);
            $fetchedAt = CarbonImmutable::parse($entry['fetched_at']);
            $oldest = $oldest === null || $fetchedAt->lessThan($oldest) ? $fetchedAt : $oldest;
        }

        return [$days, $oldest];
    }
}
