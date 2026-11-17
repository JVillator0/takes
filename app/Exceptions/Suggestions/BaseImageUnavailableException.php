<?php

declare(strict_types=1);

namespace App\Exceptions\Suggestions;

use RuntimeException;

/**
 * The proposal adds the base take's image as a reference, but the image has
 * no usable URL any more (no persisted copy, provider URL expired): apply is
 * refused with the 2-01 "image no longer available" reason (spec 4 remix
 * source order, spec 8 References).
 */
final class BaseImageUnavailableException extends RuntimeException
{
    public static function forTake(string $takeId): self
    {
        return new self("Take {$takeId} has no usable image URL to add as a reference.");
    }
}
