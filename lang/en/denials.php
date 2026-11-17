<?php

declare(strict_types=1);

/*
| Denial reasons (`App\Enums\Auth\DenialReason`, D-050), keyed by the enum
| value. Single source: the "Denial reasons" table in spec 2-01 (D-057).
| Shown inline on the composer / AI button, never as a failure.
*/

return [
    'insufficient_credits' => 'Your available credits are below the max price. Buy credits to continue.',
    'account_restricted' => 'Your account is restricted. Email :support_email for help.',
    'daily_take_cap' => "You reached today's take limit. It resets at 00:00 UTC.",
    'demo_quota_reached' => "Today's shared capacity is used up. It resets at 00:00 UTC.",
    'rate_limited' => 'You are going too fast. Try again in a moment.',
    'ai_paused' => 'Describe a change is paused right now. You can still generate takes.',
    'ai_daily_limit' => "You reached today's limit for Describe a change. It resets at 00:00 UTC.",
    'demo_account_locked' => "Shared demo accounts can't change their name, email or password. Register your own account to do that.",
];
