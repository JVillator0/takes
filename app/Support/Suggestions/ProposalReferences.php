<?php

declare(strict_types=1);

namespace App\Support\Suggestions;

use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Models\Take;
use App\Queries\Takes\ListReferenceImageUrlsQuery;
use App\Support\Takes\TakeImageUrl;

/**
 * The reference images a proposal gives the new take (spec 8 References),
 * resolved in one place for apply, the diff row and the new take's estimate,
 * so the price shown and the take created always agree.
 *
 * The model only chooses booleans. The base take's own image goes through
 * spec 4's remix source order (TakeImageUrl::forReference): the persisted copy
 * as a temporary URL, else the provider URL while it is valid, else nothing.
 * Kept references that are take images (a remix or an earlier AI change
 * stored them as signed URLs) are re-signed the same way when attached, and
 * one that no longer resolves blocks apply like the base image (#133).
 */
final class ProposalReferences
{
    /** The base image was asked for but has no usable URL. */
    public static function baseImageUnavailable(TakeChangeProposalDTO $proposal, Take $base): bool
    {
        return $proposal->addBaseImageAsReference && TakeImageUrl::forReference($base) === null;
    }

    /**
     * A reference the new take needs has no usable URL (the base image, or a
     * kept take-image reference): the P07 references row says so and apply is
     * refused.
     */
    public static function referenceUnavailable(TakeChangeProposalDTO $proposal, Take $base): bool
    {
        return self::baseImageUnavailable($proposal, $base)
            || ($proposal->keepReferences
                && count(self::keptReferences($base, dropUnavailable: true)) < count($base->reference_images));
    }

    /** How many references the new take gets: what the estimate prices. */
    public static function count(TakeChangeProposalDTO $proposal, Take $base): int
    {
        return ($proposal->keepReferences ? count($base->reference_images) : 0)
            + ($proposal->addBaseImageAsReference && ! self::baseImageUnavailable($proposal, $base) ? 1 : 0);
    }

    /**
     * The real URLs for the new take: kept references (re-signed), then the base image.
     *
     * @return list<string>
     *
     * @throws BaseImageUnavailableException when the base image or a kept take-image reference has no usable URL
     */
    public static function urls(TakeChangeProposalDTO $proposal, Take $base): array
    {
        $references = $proposal->keepReferences ? self::keptReferences($base, dropUnavailable: false) : [];

        if ($proposal->addBaseImageAsReference) {
            $references[] = TakeImageUrl::forReference($base) ?? throw BaseImageUnavailableException::forTake($base->id);
        }

        return $references;
    }

    /**
     * @return list<string>
     *
     * @throws BaseImageUnavailableException when one has no usable URL and is not dropped
     */
    private static function keptReferences(Take $base, bool $dropUnavailable): array
    {
        return app(ListReferenceImageUrlsQuery::class)->execute($base->customer_id, array_values($base->reference_images), $dropUnavailable);
    }
}
