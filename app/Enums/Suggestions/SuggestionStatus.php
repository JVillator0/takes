<?php

declare(strict_types=1);

namespace App\Enums\Suggestions;

/**
 * Lifecycle of a "Describe a change" suggestion (spec 8). A timeout is a failure_reason, not a status.
 */
enum SuggestionStatus: string
{
    case Pending = 'pending';
    case Proposed = 'proposed';
    case Applied = 'applied';
    case Discarded = 'discarded';
    case Failed = 'failed';
}
