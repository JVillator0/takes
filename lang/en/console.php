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

    'enums' => [
        'take_status' => [
            'pending' => 'Pending',
            'queued' => 'Queued',
            'processing' => 'Processing',
            'succeeded' => 'Succeeded',
            'failed' => 'Failed',
        ],
        'ledger_entry_type' => [
            'purchase' => 'Purchase',
            'promo_grant' => 'Promo grant',
            'charge' => 'Charge',
            'adjustment' => 'Adjustment',
        ],
        'customer_role' => [
            'customer' => 'Customer',
            'restricted' => 'Restricted',
            'showcase' => 'Showcase (read-only)',
        ],
    ],

    'customers' => [
        'single' => 'Customer',
        'plural' => 'Customers',
        'customer' => 'Customer',
        'role' => 'Role',
        'available' => 'Available',
        'on_hold' => 'On hold',
        'balance' => 'Balance',
        'lifetime_spend' => 'Lifetime spend',
        'profit' => 'Profit generated',
        'takes' => 'Takes',
        'takes_value' => ':takes · :failed failed',
        'last_active' => 'Last active',
        'joined' => 'Joined',
        'never' => 'Never',
        'stats' => [
            'customers' => 'Customers',
            'active_today' => 'Active today',
            'outstanding' => 'Outstanding credits (liability)',
            'holds' => 'Active holds',
            'holds_count' => ':count open usage records',
            'avg_spend' => 'Avg spend / spending customer (30 d)',
        ],
        'filters' => [
            'role' => 'Role',
            'active_since' => 'Active since',
            'low_available' => 'Available < $0.10',
            'in_progress' => 'Has takes in progress',
        ],
        'adjust' => [
            'label' => 'Adjust credits',
            'amount' => 'Amount (USD, + or −)',
            'amount_help' => 'Up to $999,999 with at most 9 decimals, e.g. +2.00 or -0.50.',
            'reason' => 'Reason (required, audited)',
            'submit' => 'Apply adjustment',
            'done' => 'Adjusted by :amount. New balance :balance.',
            'invalid_amount' => 'Enter a non-zero USD amount up to $999,999 with at most 9 decimals.',
            'insufficient' => 'Only :available is available; held credit cannot be taken back.',
        ],
        'restrict' => [
            'label' => 'Restrict',
            'unrestrict_label' => 'Unrestrict',
            'heading' => 'Restrict this customer?',
            'unrestrict_heading' => 'Unrestrict this customer?',
            'description' => 'A restricted customer cannot create takes or AI suggestions; credits and history stay.',
            'unrestrict_description' => 'The customer gets the normal customer role back.',
            'reason' => 'Reason (required, audited)',
            'restricted' => 'Customer restricted.',
            'unrestricted' => 'Customer unrestricted.',
            'unchanged' => 'No change: the customer already has that role.',
        ],
        'ledger' => [
            'title' => 'Ledger',
            'when' => 'When (UTC)',
            'type' => 'Type',
            'amount_nanos' => 'Amount (nanodollars)',
            'amount' => 'Amount',
            'reason' => 'Reason',
        ],
        'takes_tab' => [
            'title' => 'Takes',
            'take' => 'Take',
            'status' => 'Status',
            'prompt' => 'Prompt',
            'created' => 'Created (UTC)',
        ],
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
