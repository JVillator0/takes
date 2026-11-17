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
 */
final class ProposalReferences
{
    /** The base image was asked for but has no usable URL: apply is refused. */
    public static function baseImageUnavailable(TakeChangeProposalDTO $proposal, Take $base): bool
    {
        return $proposal->addBaseImageAsReference && TakeImageUrl::forReference($base) === null;
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
     * @return list<string>
     *
     * @throws BaseImageUnavailableException when the base image was asked for and has no usable URL
     */
    public static function urls(TakeChangeProposalDTO $proposal, Take $base): array
    {
        $references = $proposal->keepReferences ? array_values($base->reference_images) : [];

        if ($proposal->addBaseImageAsReference) {
            $references[] = TakeImageUrl::forReference($base) ?? throw BaseImageUnavailableException::forTake($base->id);
        }

        return $references;
    }
}
