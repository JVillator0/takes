<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\Models\Take;
use Illuminate\Support\Str;

/**
 * The take a Pixelforge generation belongs to (W-11): by `pixelforge_id`,
 * else by `metadata.app_take_id`, because a webhook can arrive before the
 * create response has stored the generation id. A take found by metadata
 * must not already carry a different generation id.
 */
final class GetTakeForGenerationQuery
{
    public function execute(?string $generationId, ?string $appTakeId): ?Take
    {
        if ($generationId !== null) {
            $take = Take::query()->where('pixelforge_id', $generationId)->first();

            if ($take !== null) {
                return $take;
            }
        }

        if ($appTakeId === null || ! Str::isUlid($appTakeId)) {
            return null;
        }

        return Take::query()
            ->whereKey(strtolower($appTakeId))
            ->where(fn ($query) => $query->whereNull('pixelforge_id')->when(
                $generationId !== null,
                fn ($query) => $query->orWhere('pixelforge_id', $generationId),
            ))
            ->first();
    }
}
