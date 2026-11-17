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

    'common' => [
        'created' => 'Created',
        'updated' => 'Updated',
        'customer' => 'Customer',
        'project' => 'Project',
        'from' => 'From',
        'until' => 'Until',
        'none' => '-',
        'utc' => ':time UTC',
    ],

    'enums' => [
        'take_source' => [
            'composer' => 'Composer',
            'remix' => 'Remix',
            'ai_change' => 'AI change',
        ],
        'usage_status' => [
            'open' => 'Open (held)',
            'settled' => 'Settled',
            'voided' => 'Voided',
        ],
        'webhook_outcome' => [
            'applied' => 'applied',
            'ignored_same_state' => 'ignored_same_state',
            'ignored_stale' => 'ignored_stale',
            'ignored_conflict' => 'ignored_conflict',
            'orphan_success' => 'orphan_success',
            'invalid_snapshot' => 'invalid_snapshot',
            'duplicate' => 'duplicate',
            'ping' => 'ping',
            'unknown_take' => 'unknown_take',
            'ignored_type' => 'ignored_type',
        ],
    ],

    'takes' => [
        'single' => 'Take',
        'plural' => 'Takes',
        'take' => 'Take',
        'customer_project' => 'Customer · Project',
        'status' => 'Status',
        'size_quality' => 'Size · Quality',
        'size' => 'Size',
        'quality' => 'Quality',
        'source' => 'Source',
        'estimate_max' => 'Est. max',
        'charged' => 'Charged',
        'provider_cost' => 'Provider cost',
        'duration' => 'Duration',
        'duration_seconds' => ':seconds s',
        'stuck' => 'Stuck > 15 min',
        'in_progress' => 'In progress',
        'failure_rate' => 'Failure rate (30 d)',
        'median_duration' => 'Median duration',
        'created_range' => 'Created',
        'title' => 'Take :id',
        'sections' => [
            'summary' => 'Summary',
            'money' => 'Money',
            'ledger' => 'Ledger',
            'timeline' => 'Timeline',
            'failure' => 'Failure',
        ],
        'fields' => [
            'id' => 'Take id',
            'pixelforge_id' => 'Pixelforge generation',
            'idempotency_key' => 'Idempotency key',
            'parent' => 'Remixed from',
            'suggestion' => 'AI suggestion',
            'prompt' => 'Prompt',
            'reference_images' => 'Reference images',
            'estimate_cost' => 'Estimate (min / max cost)',
            'estimate_charge' => 'Estimate (min / max charge)',
            'tokens' => 'Tokens (text / image / output / reasoning)',
            'provider_cost' => 'Actual provider cost',
            'charge' => 'Charge (cost × (10,000 + :bps) / 10,000)',
            'rates' => 'Rates snapshot (nano per 1M tokens)',
            'usage_status' => 'Usage record',
            'held' => 'Held (max charge while open)',
            'billing_date' => 'Billing day (UTC)',
            'charge_entry' => 'Ledger charge entry',
            'charge_entry_value' => '#:id · :amount',
            'created_at' => 'Created',
            'submitted_at' => 'Submitted',
            'started_at' => 'Started',
            'completed_at' => 'Completed',
            'last_event_at' => 'Last provider update',
            'submit_attempts' => 'Submit attempts',
            'last_submit_error' => 'Last submit error',
            'attention_reason' => 'Needs attention',
            'failure_code' => 'Failure code',
            'failure_message' => 'Message',
        ],
        'reconcile' => [
            'label' => 'Reconcile now',
            'heading' => 'Reconcile this take now?',
            'description' => 'Fetches the generation from Pixelforge and applies it through the normal path. Safe to repeat.',
            'no_change' => 'No change',
            'applied' => 'Applied: :state',
            'applied_charged' => 'Applied: :state, charged :amount',
        ],
    ],

    'projects' => [
        'single' => 'Project',
        'plural' => 'Projects',
        'name' => 'Project',
        'takes' => 'Takes',
        'spend' => 'Spend',
        'in_progress' => 'Has takes in progress',
        'archived' => 'Archived',
    ],

    'webhooks' => [
        'single' => 'Webhook delivery',
        'plural' => 'Webhook deliveries',
        'received_at' => 'Received (UTC)',
        'event_id' => 'Event id',
        'type' => 'Type',
        'take' => 'Take',
        'attempt' => 'Attempt',
        'outcome' => 'Outcome',
        'payload' => 'Payload',
        'view_payload' => 'View JSON',
        'deliveries_24h' => 'Deliveries (24 h)',
        'duplicates' => 'Duplicates',
        'ignored_stale' => 'Ignored stale',
        'unknown_orphan' => 'Unknown take / orphan success',
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
