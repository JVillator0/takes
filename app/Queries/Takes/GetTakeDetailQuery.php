<?php

declare(strict_types=1);

namespace App\Queries\Takes;

use App\DTOs\Takes\TakeDetailDTO;
use App\Enums\Takes\ReferenceResolution;
use App\Models\Take;

/**
 * A take for its drawer (P06, `?take=` and `GET /takes/{take}`): the usage
 * for the price, the parent for "Compare with parent", the lineage for the
 * version strip, all numbered, and the reference images re-issued for
 * display: a stored take-image reference is a short-lived signed URL, so it
 * is re-signed from our persisted copy and left out when there is none
 * (#140, #167: never the provider's URL, D-023). The resource tells Remix and
 * Try again how many were left out. The caller has already checked ownership.
 */
final class GetTakeDetailQuery
{
    /** The strip shows at most this many ancestors and this many remixes. */
    private const int MAX_LINEAGE = 8;

    public function __construct(
        private readonly ListTakeNumbersQuery $numbers,
        private readonly ListReferenceImageUrlsQuery $referenceImages,
    ) {}

    public function execute(Take $take): TakeDetailDTO
    {
        $take->loadMissing('usageRecord');

        $ancestors = [];
        $cursor = $take;

        while ($cursor->parent_take_id !== null && count($ancestors) < self::MAX_LINEAGE) {
            $parent = Take::query()
                ->whereKey($cursor->parent_take_id)
                ->where('customer_id', $take->customer_id)
                ->first();

            if ($parent === null) {
                break;
            }

            array_unshift($ancestors, $parent);
            $cursor = $parent;
        }

        $remixes = Take::query()
            ->where('parent_take_id', $take->id)
            ->where('customer_id', $take->customer_id)
            ->oldest()
            ->oldest('id')
            ->limit(self::MAX_LINEAGE)
            ->get()
            ->all();

        $versions = $ancestors === [] && $remixes === [] ? [] : [...$ancestors, $take, ...$remixes];
        $parent = $ancestors === [] ? null : $ancestors[array_key_last($ancestors)];

        $numbers = $this->numbers->execute(array_map(fn (Take $version): string => $version->id, [$take, ...$versions]));

        foreach ([$take, ...$versions] as $version) {
            $version->setAttribute('number', $numbers[$version->id] ?? null);
        }

        $take->setAttribute('parent_number', $parent !== null ? ($numbers[$parent->id] ?? null) : null);

        return new TakeDetailDTO(
            $take,
            $parent,
            $versions,
            $this->referenceImages->execute($take->customer_id, array_values($take->reference_images), ReferenceResolution::Display),
        );
    }
}
