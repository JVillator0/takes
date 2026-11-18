<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Billing and credit copy (single source, D-057)
|--------------------------------------------------------------------------
|
| Every string is listed in .claude/specs/2-product-design/01-SPEC.md (the
| "Billing, take and AI strings" and "Additional strings" tables).
| Amounts (:amount) are formatted on the server: 4 decimals on the pill and
| toasts, exact on money pages (D-042). 1 credit = $1.00.
|
*/

return [

    /* 2-01: Billing, take and AI strings. */
    'purchase_success' => 'Credits added',
    'balance_footnote' => 'Balance excludes amounts on hold',
    'negative_balance' => 'Your balance is below zero by :amount. Buy credits to continue.',

    /* 2-01: Additional strings (keys as named in the spec). */
    'simulated_notice' => 'This is a simulated payment for the take-home. No card, nothing is charged.',
    'pay' => 'Pay :amount (simulated)',
    'view_invoice' => 'View invoice',
    'spent_this_month' => 'Spent this month',
    'purchase_line' => 'Takes credits (simulated purchase)',

    /* Balance pill and Buy credits entry points (P03, P04, P10). */
    'buy_credits' => 'Buy credits',
    'packs_label' => 'Choose a credit pack',
    'available' => 'Available',
    'on_hold' => 'on hold',
    'balance_label' => 'Available :available, on hold :on_hold',
    'balance_exact' => 'Exact: available :available, on hold :on_hold',
    'on_hold_link' => 'Show the takes in progress.',

    /* Billing page (P08), purchase flow and invoices (P09), spec 6. */
    'page_title' => 'Billing',
    'on_hold_title' => 'On hold',
    'balance_breakdown' => 'Balance :balance, on hold :on_hold',
    'on_hold_hint' => 'Up to this much is held for takes and suggestions in progress.',
    'spent_by_settlement' => ':month, by settlement date (UTC)',
    'features' => [
        'images' => 'Images',
        'ai_suggestions' => 'AI suggestions',
    ],
    'packs_hint' => 'Simulated payment. Each purchase creates a paid invoice.',
    'buy_pack' => 'Buy :amount',
    'buy_title' => 'Buy :amount of credits',
    'tax' => 'Tax',
    'total' => 'Total',
    'cancel' => 'Cancel',
    'processing' => 'Processing…',
    'purchase_failed' => 'The purchase did not go through. You were not charged. Try again.',
    'purchase_unconfirmed' => "We couldn't confirm the purchase. Press Pay again in this window to check it. You will not be charged twice.",
    'tabs' => [
        'label' => 'Billing history',
        'activity' => 'Activity',
        'invoices' => 'Invoices',
    ],
    'activity' => [
        'caption' => 'Activity, newest first. Amounts are exact.',
        'empty' => 'No activity yet.',
        'date' => 'Date (UTC)',
        'description' => 'Description',
        'status' => 'Status',
        'amount' => 'Amount',
        'balance' => 'Balance',
        'up_to' => 'Up to :amount',
        'was_held' => 'up to :amount was held',
        'open_take' => 'Open take',
        'invoice' => 'Invoice :number',
        'earlier' => 'Earlier entries',
        'newer' => 'Newer entries',
        'kind' => [
            'image' => 'Image',
            'ai_suggestion' => 'AI suggestion',
            'welcome_credit' => 'Welcome credit',
            'promo_grant' => 'Promotional credit',
            'adjustment' => 'Credit adjustment',
        ],
        'status_label' => [
            'on_hold' => 'On hold',
            'charged' => 'Charged',
            'not_charged' => 'Not charged',
        ],
    ],
    'invoices' => [
        'caption' => 'Invoices, newest first.',
        'empty' => 'No invoices yet. Each purchase creates one.',
        'number' => 'Number',
        'issued' => 'Issued',
        'total' => 'Total',
        'status' => 'Status',
        'view' => 'View',
        'view_number' => 'View invoice :number',
        'note' => 'Only purchases are invoiced. The welcome credit is not a sale, and usage is paid from prepaid credits and listed in Activity.',
    ],
    'invoice_status' => [
        'paid' => 'Paid',
    ],
    'invoice' => [
        'title' => 'Invoice',
        'issued' => 'Issued :date',
        'billed_to' => 'Billed to',
        'from' => 'From',
        'merchant' => 'Takes (demo)',
        'merchant_note' => 'Simulated merchant',
        'description' => 'Description',
        'quantity' => 'Qty',
        'unit' => 'Unit',
        'amount' => 'Amount',
        'tax' => 'Tax (not applicable in demo)',
        'total_paid' => 'Total paid',
        'payment' => 'Payment: simulated, reference :reference',
        'no_expiry' => 'Credits do not expire.',
        'print' => 'Print / Save as PDF',
        'back' => 'Back to billing',
    ],

];
