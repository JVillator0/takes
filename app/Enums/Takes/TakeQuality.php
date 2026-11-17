<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * Quality levels Pixelforge accepts (spec 3-02).
 */
enum TakeQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
