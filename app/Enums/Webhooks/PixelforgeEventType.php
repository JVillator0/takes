<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

/**
 * Event types Pixelforge documents (https://pixelforge.promptedit.dev/docs,
 * "Webhooks"). Anything else is acknowledged as `ignored_type` (W-10).
 */
enum PixelforgeEventType: string
{
    case GenerationProcessing = 'generation.processing';
    case GenerationSucceeded = 'generation.succeeded';
    case GenerationFailed = 'generation.failed';

    /** "Send test event" in the dashboard. */
    case Ping = 'ping';

    /** Carries a generation object in `data.object`. */
    public function isGeneration(): bool
    {
        return $this !== self::Ping;
    }
}
