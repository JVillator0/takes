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

    /**
     * Whether the AI call is over, so its usage is settled or voided and no
     * credit is held. Only `pending` still holds credit: CreateSuggestionAction
     * T2 settles the usage in the transaction that moves it to `proposed` (or
     * voids it on `failed`), and `applied`/`discarded` come after `proposed`.
     * Not a "no further transitions" test: `proposed` still moves on.
     */
    public function aiCallIsOver(): bool
    {
        return $this !== self::Pending;
    }
}
