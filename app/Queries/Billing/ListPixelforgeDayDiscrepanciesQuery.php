<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\FindTakesResultDTO;
use App\DTOs\Billing\ReconciliationGenerationDTO;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money\Nanodollars;
use App\Support\Money\TokenCost;
use Carbon\CarbonImmutable;
use Psr\Log\NullLogger;

/**
 * A04 **Find takes** (D-034, D-058): pages `GET /v1/generations?status=succeeded`, keeps the generations Pixelforge
 * completed on the day (UTC), and diffs them against our takes by `pixelforge_id` (or `metadata.app_take_id`).
 * Runs only on an explicit click; never on page load.
 */
final readonly class ListPixelforgeDayDiscrepanciesQuery
{
    /** Provider pages read at most (100 generations each). */
    public const int MAX_PAGES = 20;

    private TokenCost $tokenCost;

    public function __construct(private PixelforgeClient $client)
    {
        // Re-pricing for display must not re-log settlement's rounding warning.
        $this->tokenCost = new TokenCost(new NullLogger);
    }

    public function execute(CarbonImmutable $day): FindTakesResultDTO
    {
        $date = $day->utc()->toDateString();
        [$generations, $truncated] = $this->generationsCompletedOn($day->utc()->startOfDay());

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
