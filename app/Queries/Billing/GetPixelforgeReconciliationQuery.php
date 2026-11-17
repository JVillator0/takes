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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Providers › Pixelforge usage (A04, D-034): per UTC billing day, our settled Pixelforge usage (generations, the
 * four token sums, cost) against `GET /v1/usage`, cached 5 minutes per range. A day is Pending while takes created
 * that day are still in flight, and today always is ("N in flight"), never Mismatch. When Pixelforge cannot be
 * reached the last good answer for the range is shown, marked unavailable.
 */
final readonly class GetPixelforgeReconciliationQuery
{
    public const int MAX_DAYS = 92;

    public const int CACHE_SECONDS = 300;

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
        [$theirs, $fetchedAt, $unavailable] = $this->theirs($from, $until, $refresh);
        $today = CarbonImmutable::now('UTC')->toDateString();

        $days = [];
        $oursTotal = UsageTotalsDTO::zero();
        $theirsTotal = $theirs === null ? null : UsageTotalsDTO::zero();
        $matched = 0;
        $judged = 0;

        for ($day = $until; $day->greaterThanOrEqualTo($from); $day = $day->subDay()) {
            $date = $day->toDateString();
            $ourDay = $ours[$date] ?? UsageTotalsDTO::zero();
            $theirDay = $theirs === null ? null : ($theirs[$date] ?? UsageTotalsDTO::zero());
            $flying = $inFlight[$date] ?? 0;

            $status = match (true) {
                $theirDay === null => ReconciliationStatus::Unavailable,
                $date === $today || $flying > 0 => ReconciliationStatus::Pending,
                $ourDay->equals($theirDay) => ReconciliationStatus::Match,
                default => ReconciliationStatus::Mismatch,
            };

            if ($status === ReconciliationStatus::Match || $status === ReconciliationStatus::Mismatch) {
                $judged++;
                $matched += $status === ReconciliationStatus::Match ? 1 : 0;
            }

            $oursTotal = $oursTotal->plus($ourDay);
            $theirsTotal = $theirDay === null || $theirsTotal === null ? $theirsTotal : $theirsTotal->plus($theirDay);
            $days[] = new ReconciliationDayDTO($date, $ourDay, $theirDay, $flying, $status);
        }

        return new PixelforgeReconciliationDTO($days, $oursTotal, $theirsTotal, $matched, $judged, $fetchedAt, $unavailable);
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
     * Pixelforge's figures for the range: cached 5 minutes; on failure the last good answer, marked unavailable.
     *
     * @return array{0: array<string, UsageTotalsDTO>|null, 1: CarbonImmutable|null, 2: bool}
     */
    private function theirs(CarbonImmutable $from, CarbonImmutable $until, bool $refresh): array
    {
        $key = 'admin:pixelforge-usage:'.$from->toDateString().':'.$until->toDateString();

        if ($refresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);

        if (! is_array($cached)) {
            try {
                $report = $this->client->getUsage($from, $until);
            } catch (PixelforgeException|PixelforgeNotConfigured) {
                $last = Cache::get($key.':last');

                return is_array($last) ? [...$this->decode($last), true] : [null, null, true];
            }

            $cached = ['fetched_at' => CarbonImmutable::now()->toIso8601String(), 'days' => []];

            foreach ($report->days as $day) {
                $cached['days'][$day->date->toDateString()] = (new UsageTotalsDTO(
                    $day->generations,
                    $day->inputTextTokens,
                    $day->inputImageTokens,
                    $day->outputTokens,
                    $day->reasoningTokens,
                    $day->cost,
                ))->toArray();
            }

            Cache::put($key, $cached, self::CACHE_SECONDS);
            Cache::forever($key.':last', $cached);
        }

        return [...$this->decode($cached), false];
    }

    /**
     * @param  array<mixed>  $cached
     * @return array{0: array<string, UsageTotalsDTO>, 1: CarbonImmutable|null}
     */
    private function decode(array $cached): array
    {
        $days = [];

        foreach (is_array($cached['days'] ?? null) ? $cached['days'] : [] as $date => $totals) {
            /** @var array{generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_nanos: int} $totals */
            $days[(string) $date] = UsageTotalsDTO::fromArray($totals);
        }

        $fetchedAt = is_string($cached['fetched_at'] ?? null) ? CarbonImmutable::parse($cached['fetched_at']) : null;

        return [$days, $fetchedAt];
    }
}
