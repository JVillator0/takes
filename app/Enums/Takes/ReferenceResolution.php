<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * What ListReferenceImageUrlsQuery does with a take-image reference that no
 * longer resolves. Both re-sign through TakeImageUrl::forReference (spec 4
 * remix source order: the persisted copy, else the provider's URL while it
 * is valid, the documented fallback until the image is persisted).
 */
enum ReferenceResolution: string
{
    /** Creating a take (composer, remix, Try again, AI apply): refused. */
    case Attach = 'attach';

    /**
     * Checking a proposal before apply, and the drawer that pre-fills Remix
     * and Try again: left out, so a caller can count exactly what Attach
     * would refuse.
     */
    case Check = 'check';
}
