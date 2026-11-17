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
            'avg_spend' => 'Avg spend / active customer (30 d)',
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
