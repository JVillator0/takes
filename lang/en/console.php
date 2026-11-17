<?php

declare(strict_types=1);

/*
| Admin console copy (spec 7, 2-03). Every label, heading and notification text in app/Filament comes from here.
*/

return [

    'groups' => [
        'operations' => 'Operations',
        'system' => 'System',
    ],

    'clusters' => [
        'studio' => 'Studio',
        'billing' => 'Billing',
        'providers' => 'Providers',
    ],

    'dashboard' => [
        'title' => 'Dashboard',
    ],

    'pixelforge' => [
        'title' => 'Pixelforge usage',
        'from' => 'From (UTC)',
        'until' => 'Until (UTC)',
        'refresh' => 'Refresh',
        'day' => 'Day (UTC)',
        'generations' => 'Generations (ours / theirs)',
        'our_cost_column' => 'Our provider cost',
        'their_cost_column' => 'Pixelforge cost_usd',
        'difference' => 'Difference',
        'tokens' => 'Tokens differ (ours − theirs)',
        'token_kinds' => [
            'input_text_tokens' => 'text',
            'input_image_tokens' => 'image',
            'output_tokens' => 'output',
            'reasoning_tokens' => 'reasoning',
        ],
        'today' => ':date (today)',
        'status_column' => 'Status',
        'in_flight' => ':count in flight',
        'total' => 'Total',
        'summary' => ':matched of :judged closed days match',
        'fetched' => 'Pixelforge figures fetched :time UTC (cached 5 min).',
        'unavailable' => 'Could not reach Pixelforge. Last fetched :time UTC.',
        'unavailable_never' => 'Could not reach Pixelforge.',
        'unavailable_short' => 'Could not reach Pixelforge.',
        'find_takes' => 'Find takes',
        'throttled' => 'Pixelforge checks are limited to protect the shared account budget. Try again in :seconds s.',
        'not_a_mismatch' => 'Find takes runs only for a Mismatch day of the current range.',
        'invalid_date' => 'Enter a date as YYYY-MM-DD.',
        'invalid_range' => 'Pick an ordered range of at most :days days.',
        'footnote' => 'Billing day = the UTC day the generation completed (provider\'s completed_at), same rule as Pixelforge. 9 decimals here because this page exists to compare exact values.',
        'find_heading' => 'Find takes · :date',
        'truncated' => 'Pixelforge listed more generations than were read; the groups may be incomplete.',
        'nothing' => 'Nothing differs for this day.',
        'generation' => 'Generation',
        'take' => 'Take',
        'their_cost' => 'Pixelforge cost',
        'our_cost' => 'Our cost',
        'groups' => [
            'theirs_not_ours' => 'Theirs, not ours',
            'ours_not_settled' => 'Ours, not settled',
            'amount_differs' => 'Amount differs',
            'orphan_success' => 'Orphan success (cost absorbed)',
        ],
        'status' => [
            'match' => 'Match',
            'mismatch' => 'Mismatch',
            'pending' => 'Pending',
            'unavailable' => 'Provider unavailable',
        ],
    ],

    'audit' => [
        'entry' => 'Admin audit entry',
        'title' => 'Activity log',
    ],

    'demo_access' => [
        'heading' => 'Evaluator access',
        'body' => 'Read-only auditor account. It can open every page and report but cannot change anything.',
        'email' => 'Email',
        'password' => 'Password',
    ],

];
