<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Every billable provider goes through the same usage records and ledger (D-009).
 */
enum Provider: string
{
    case Pixelforge = 'pixelforge';
    case Anthropic = 'anthropic';
}
