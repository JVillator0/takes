<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Take;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Takes\TakeImageUrl;

/**
 * A project card on P03: cover (newest finished take), take count, spend
 * (settled charges, 4 decimals on the card with the exact on hover, D-042)
 * and the In progress count (2-02). Mirrors `ProjectSummary` in
 * resources/js/types/projects.ts.
 */
final class ProjectResource
{
    /**
     * @return array{id: string, name: string, takeCount: int, inProgressCount: int, spend: string, spendExact: string, coverUrl: string|null, updatedAt: string|null}
     */
    public static function summary(Project $project, ?Take $cover, Nanodollars $spend): array
    {
        $lastTakeAt = $project->getAttribute('last_take_at');

        return [
            'id' => $project->id,
            'name' => $project->name,
            'takeCount' => (int) $project->getAttribute('takes_count'),
            'inProgressCount' => (int) $project->getAttribute('in_progress_count'),
            'spend' => $spend->format(4, RoundingMode::HalfUp),
            'spendExact' => $spend->formatExact(),
            'coverUrl' => $cover !== null ? TakeImageUrl::forDisplay($cover) : null,
            'updatedAt' => is_string($lastTakeAt)
                ? now()->parse($lastTakeAt)->toIso8601ZuluString()
                : $project->updated_at?->toIso8601ZuluString(),
        ];
    }
}
