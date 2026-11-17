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
    'available' => 'Available',
    'on_hold' => 'on hold',
    'balance_label' => 'Available :available, on hold :on_hold',

];
