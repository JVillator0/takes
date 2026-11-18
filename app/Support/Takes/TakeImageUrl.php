<?php

declare(strict_types=1);

namespace App\Support\Takes;

use App\Models\Take;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where the browser (or Pixelforge, for a remix reference) can load a
 * take's image right now (spec 4 "Persist image", "Remix and reference
 * images"):
 *
 * 1. the persisted copy as a short-lived temporary URL of the default disk;
 * 2. the provider's signed URL while it is valid (24 h after completion);
 * 3. nothing: the UI shows the 2-01 "image no longer available" string.
 *
 * A disk without temporary URLs falls back to the owner-only image route.
 */
final class TakeImageUrl
{
    /** Grid and drawer: the URL is re-issued on every page load. */
    public const int DISPLAY_MINUTES = 15;

    /** A remix reference must outlive the submit window (spec 4). */
    public const int REFERENCE_MINUTES = 60;

    public static function forDisplay(Take $take): ?string
    {
        return self::resolve($take, self::DISPLAY_MINUTES, fallbackToRoute: true);
    }

    /** Null disables "Use image as reference" with the 2-01 reason. */
    public static function forReference(Take $take): ?string
    {
        return self::resolve($take, self::REFERENCE_MINUTES, fallbackToRoute: false);
    }

    /**
     * A reference URL the customer may see: the persisted copy as a temporary
     * URL, never the provider's signed URL (D-023, M-23); null without one.
     */
    public static function forPersistedReference(Take $take): ?string
    {
        if ($take->image_path === null) {
            return null;
        }

        try {
            return Storage::temporaryUrl($take->image_path, CarbonImmutable::now()->addMinutes(self::REFERENCE_MINUTES));
        } catch (Throwable $exception) {
            Log::debug('No temporary URL for a take image.', ['take_id' => $take->id, 'reason' => $exception->getMessage()]);

            return null;
        }
    }

    private static function resolve(Take $take, int $minutes, bool $fallbackToRoute): ?string
    {
        if ($take->image_path !== null) {
            try {
                return Storage::temporaryUrl($take->image_path, CarbonImmutable::now()->addMinutes($minutes));
            } catch (Throwable $exception) {
                Log::debug('No temporary URL for a take image.', ['take_id' => $take->id, 'reason' => $exception->getMessage()]);

                if ($fallbackToRoute) {
                    return route('takes.image', $take);
                }
            }
        }

        return self::providerUrlIsValid($take) ? $take->image_url : null;
    }

    private static function providerUrlIsValid(Take $take): bool
    {
        if ($take->image_url === null) {
            return false;
        }

        $expiresAt = $take->image_url_expires_at ?? $take->completed_at?->addDay();

        return $expiresAt !== null && $expiresAt->isFuture();
    }
}
