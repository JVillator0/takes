<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * What PersistTakeImageAction did with a take's provider image (spec 4
 * "Persist image", D-020). Only `stored` writes; the refusals are final
 * (retrying cannot change the answer), while network errors throw so the
 * job retries.
 */
enum ImagePersistOutcome: string
{
    case Stored = 'stored';
    case AlreadyStored = 'already_stored';
    case NoImage = 'no_image';
    case WrongHost = 'wrong_host';
    case NotPng = 'not_png';
    case TooLarge = 'too_large';

    /** The URL answered a final 4xx (expired or gone; not 408 or 429): retrying cannot help. */
    case Unavailable = 'unavailable';
}
