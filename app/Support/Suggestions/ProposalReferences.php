<?php

declare(strict_types=1);

namespace App\Support\Suggestions;

use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Models\Take;
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
 * stored them as signed URLs) are re-signed by ListReferenceImageUrlsQuery
 * before they reach here (the caller resolves them once), and one that no
 * longer resolves blocks apply like the base image (#133).
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
     *
     * @param  list<string>  $resolvedKept  the base take's references resolved with ReferenceResolution::Check
     */
    public static function referenceUnavailable(TakeChangeProposalDTO $proposal, Take $base, array $resolvedKept): bool
    {
        return self::baseImageUnavailable($proposal, $base)
            || ($proposal->keepReferences && count($resolvedKept) < count($base->reference_images));
    }

    /** How many references the new take gets: what the estimate prices. */
    public static function count(TakeChangeProposalDTO $proposal, Take $base): int
    {
        return ($proposal->keepReferences ? count($base->reference_images) : 0)
            + ($proposal->addBaseImageAsReference && ! self::baseImageUnavailable($proposal, $base) ? 1 : 0);
    }

    /**
     * The real URLs for the new take: kept references, then the base image.
     *
     * @param  list<string>  $resolvedKept  the base take's references resolved with ReferenceResolution::Attach
     * @return list<string>
     *
     * @throws BaseImageUnavailableException when the base image was asked for and has no usable URL
     */
    public static function urls(TakeChangeProposalDTO $proposal, Take $base, array $resolvedKept): array
    {
        $references = $proposal->keepReferences ? $resolvedKept : [];

        if ($proposal->addBaseImageAsReference) {
            $references[] = TakeImageUrl::forReference($base) ?? throw BaseImageUnavailableException::forTake($base->id);
        }

        return $references;
    }
}
