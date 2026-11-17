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
        'cost' => 'Cost (ours / theirs)',
        'tokens' => 'Tokens differ (ours − theirs)',
        'status_column' => 'Status',
        'in_flight' => ':count in flight',
        'total' => 'Total',
        'summary' => ':matched / :judged days match',
        'fetched' => 'Pixelforge figures fetched :time UTC (cached 5 min).',
        'unavailable' => 'Could not reach Pixelforge. Showing the last figures fetched :time UTC.',
        'unavailable_never' => 'Could not reach Pixelforge, and no earlier figures are cached for this range.',
        'unavailable_short' => 'Could not reach Pixelforge.',
        'find_takes' => 'Find takes',
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
