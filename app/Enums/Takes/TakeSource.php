<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * Where a take came from: the composer, a remix of another take, or an AI change (spec 3-02).
 */
enum TakeSource: string
{
    case Composer = 'composer';
    case Remix = 'remix';
    case AiChange = 'ai_change';
}
