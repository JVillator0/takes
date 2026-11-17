<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

/**
 * The one rule for a Pixelforge generation id (`gen_` + a ULID-like body),
 * shared by GenerationSnapshotDTO (what we store as `takes.pixelforge_id`)
 * and PixelforgeClient::getGeneration() (what we can fetch again), so every
 * id we accept can be fetched later. The body is URL-path safe and fits the
 * 255-character column.
 */
final class GenerationId
{
    public const string PATTERN = '/^gen_[A-Za-z0-9_-]{1,251}$/D';

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }
}
