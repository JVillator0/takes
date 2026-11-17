<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\FindTakesResultDTO;
use App\DTOs\Billing\ReconciliationGenerationDTO;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money\Nanodollars;
use App\Support\Money\TokenCost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;

/**
 * A04 **Find takes** (D-034, D-058): pages `GET /v1/generations?status=succeeded`, keeps the generations Pixelforge
 * completed on the day (UTC), and diffs them against our takes by `pixelforge_id` (or `metadata.app_take_id`).
 * Our settled usage on the day that Pixelforge did not list also lands in Amount differs (their cost unknown).
 * Runs only on an explicit click, never on page load; each day's result is cached 5 minutes.
 */
final readonly class ListPixelforgeDayDiscrepanciesQuery
{
    /** Provider pages read at most (100 generations each). */
    public const int MAX_PAGES = 20;

    /** A day's result is reused this long, so repeated clicks do not spend the shared 60 rpm budget. */
    public const int CACHE_SECONDS = 300;

    private TokenCost $tokenCost;

    public function __construct(private PixelforgeClient $client)
    {
        // Re-pricing for display must not re-log settlement's rounding warning.
        $this->tokenCost = new TokenCost(new NullLogger);
    }

    public static function cacheKey(string $date): string
    {
        return 'admin:pixelforge-find:'.$date;
    }

    public function execute(CarbonImmutable $day): FindTakesResultDTO
    {
        $date = $day->utc()->toDateString();
        $cached = Cache::get(self::cacheKey($date));

        if ($cached instanceof FindTakesResultDTO) {
            return $cached;
        }

        $result = $this->discrepancies($day->utc()->startOfDay());
        Cache::put(self::cacheKey($date), $result, self::CACHE_SECONDS);

        return $result;
    }

    private function discrepancies(CarbonImmutable $dayStart): FindTakesResultDTO
    {
        $date = $dayStart->toDateString();
        [$generations, $truncated] = $this->generationsCompletedOn($dayStart);

        $takes = Take::query()
            ->with('usageRecord')
            ->where(fn ($query) => $query
                ->whereIn('pixelforge_id', array_map(fn (GenerationSnapshotDTO $generation): string => $generation->id, $generations))
                ->orWhereIn('id', array_values(array_filter(array_map(fn (GenerationSnapshotDTO $generation): ?string => $generation->appTakeId(), $generations)))))
            ->get();
        $byGenerationId = $takes->filter(fn (Take $take): bool => $take->pixelforge_id !== null)->keyBy('pixelforge_id');
        $byTakeId = $takes->keyBy('id');

        $groups = ['theirs' => [], 'unsettled' => [], 'differs' => [], 'orphan' => []];

        foreach ($generations as $generation) {
            $take = $byGenerationId->get($generation->id) ?? $this->takeByMetadata($byTakeId->get((string) $generation->appTakeId()), $generation);
            $theirCost = $this->cost($generation);
            $usage = $take?->usageRecord;
            $ourCost = $usage?->provider_cost_nanos === null ? null : Nanodollars::fromInt($usage->provider_cost_nanos);
            $row = new ReconciliationGenerationDTO($generation->id, $take?->id, $theirCost, $ourCost);

            $group = match (true) {
                $take === null => 'theirs',
                $take->status === TakeStatus::Failed => 'orphan',
                $usage === null || $usage->status !== UsageStatus::Settled => 'unsettled',
                ! $this->settledAsBilled($usage, $generation, $date, $theirCost) => 'differs',
                default => null,
            };

            if ($group !== null) {
                $groups[$group][] = $row;
            }
        }

        // Our side billing more: settled usage on this day whose generation Pixelforge did not list as completed on
        // it. Only knowable when the whole list was read.
        if (! $truncated) {
            $listed = array_map(fn (GenerationSnapshotDTO $generation): string => $generation->id, $generations);

            UsageRecord::query()
                ->with('billable')
                ->where('provider', Provider::Pixelforge->value)
                ->where('status', UsageStatus::Settled->value)
                ->whereDate('billing_date', $date)
                ->get()
                ->each(function (UsageRecord $usage) use ($listed, &$groups): void {
                    $take = $usage->billable;
                    $generationId = $take instanceof Take ? $take->pixelforge_id : null;

                    if ($generationId === null || ! in_array($generationId, $listed, true)) {
                        $groups['differs'][] = new ReconciliationGenerationDTO(
                            $generationId ?? '',
                            $take instanceof Take ? $take->id : null,
                            null,
                            $usage->provider_cost_nanos === null ? null : Nanodollars::fromInt($usage->provider_cost_nanos),
                        );
                    }
                });
        }

        return new FindTakesResultDTO($date, $groups['theirs'], $groups['unsettled'], $groups['differs'], $groups['orphan'], $truncated);
    }

    /**
     * The metadata fallback (a webhook can arrive before we stored the generation id) applies only to a take with
     * no generation yet or this very one: a second generation billed for a take already linked to another
     * generation is Pixelforge's, not ours.
     */
    private function takeByMetadata(?Take $take, GenerationSnapshotDTO $generation): ?Take
    {
        return $take !== null && ($take->pixelforge_id === null || $take->pixelforge_id === $generation->id) ? $take : null;
    }

    /**
     * The settled usage matches what Pixelforge billed: same UTC billing day, cost and all four token counts.
     */
    private function settledAsBilled(UsageRecord $usage, GenerationSnapshotDTO $generation, string $date, ?Nanodollars $theirCost): bool
    {
        $tokens = $generation->usage;

        return $usage->billing_date?->toDateString() === $date
            && $theirCost !== null
            && $usage->provider_cost_nanos !== null
            && Nanodollars::fromInt($usage->provider_cost_nanos)->equals($theirCost)
            && $tokens !== null
            && [$usage->input_text_tokens, $usage->input_image_tokens, $usage->output_tokens, $usage->reasoning_tokens]
                === [$tokens->inputTextTokens, $tokens->inputImageTokens, $tokens->outputTokens, $tokens->reasoningTokens];
    }

    /**
     * Pixelforge lists newest first, so paging stops once a whole page was created more than a day before the
     * target day (a generation finishes in minutes), or after MAX_PAGES on the shared 60 rpm budget.
     *
     * @return array{0: list<GenerationSnapshotDTO>, 1: bool}
     */
    private function generationsCompletedOn(CarbonImmutable $dayStart): array
    {
        $date = $dayStart->toDateString();
        $stopBefore = $dayStart->subDay();
        $found = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->client->listGenerations('succeeded', $cursor);

            foreach ($result->generations as $generation) {
                if ($generation->completedAt?->utc()->toDateString() === $date) {
                    $found[] = $generation;
                }
            }

            $pageIsOlder = $result->generations !== []
                && array_filter($result->generations, fn (GenerationSnapshotDTO $generation): bool => $generation->createdAt->greaterThanOrEqualTo($stopBefore)) === [];

            if (! $result->hasMore || $result->nextCursor === null || $pageIsOlder) {
                return [$found, false];
            }

            $cursor = $result->nextCursor;
        }

        return [$found, true];
    }

    private function cost(GenerationSnapshotDTO $generation): ?Nanodollars
    {
        if ($generation->usage === null || $generation->rates === null) {
            return null;
        }

        return $this->tokenCost->compute($generation->usage->toUnits(), $generation->rates->toNanosPerMillion());
    }
}
