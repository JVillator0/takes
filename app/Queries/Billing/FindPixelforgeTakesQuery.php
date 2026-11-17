<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\FindTakesResultDTO;
use App\DTOs\Billing\ReconciliationGenerationDTO;
use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\Take;
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
final readonly class FindPixelforgeTakesQuery
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
        [$generations, $truncated] = $this->generationsCompletedOn($date);

        $takes = Take::query()
            ->with('usageRecord')
            ->where(fn ($query) => $query
                ->whereIn('pixelforge_id', array_map(fn (GenerationSnapshotDTO $generation): string => $generation->id, $generations))
                ->orWhereIn('id', array_values(array_filter(array_map(fn (GenerationSnapshotDTO $generation): ?string => $generation->appTakeId(), $generations)))))
            ->get();

        $groups = ['theirs' => [], 'unsettled' => [], 'differs' => [], 'orphan' => []];

        foreach ($generations as $generation) {
            $take = $takes->first(fn (Take $take): bool => $take->pixelforge_id === $generation->id || $take->id === $generation->appTakeId());
            $theirCost = $this->cost($generation);
            $usage = $take?->usageRecord;
            $ourCost = $usage?->provider_cost_nanos === null ? null : Nanodollars::fromInt($usage->provider_cost_nanos);
            $row = new ReconciliationGenerationDTO($generation->id, $take?->id, $theirCost, $ourCost);

            $group = match (true) {
                $take === null => 'theirs',
                $take->status === TakeStatus::Failed => 'orphan',
                $usage === null || $usage->status !== UsageStatus::Settled => 'unsettled',
                $usage->billing_date?->toDateString() !== $date || $ourCost === null || $theirCost === null || ! $ourCost->equals($theirCost) => 'differs',
                default => null,
            };

            if ($group !== null) {
                $groups[$group][] = $row;
            }
        }

        return new FindTakesResultDTO($date, $groups['theirs'], $groups['unsettled'], $groups['differs'], $groups['orphan'], $truncated);
    }

    /**
     * @return array{0: list<GenerationSnapshotDTO>, 1: bool}
     */
    private function generationsCompletedOn(string $date): array
    {
        $found = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->client->listGenerations('succeeded', $cursor);

            foreach ($result->generations as $generation) {
                if ($generation->completedAt?->utc()->toDateString() === $date) {
                    $found[] = $generation;
                }
            }

            if (! $result->hasMore || $result->nextCursor === null) {
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
