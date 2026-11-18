<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * What a take-image reference is resolved for (ListReferenceImageUrlsQuery).
 * The URL goes to Pixelforge on `Attach` and `Check`, and to the customer's
 * browser on `Display`, which therefore never gets the provider's signed URL
 * (D-023, M-23).
 */
enum ReferenceResolution: string
{
    /** Creating a take: re-signed, an unresolvable one is refused. */
    case Attach = 'attach';

    /** Checking a proposal before apply: re-signed, an unresolvable one is left out. */
    case Check = 'check';

    /** The drawer (Remix, Try again): our persisted copy only, anything else left out. */
    case Display = 'display';
}
