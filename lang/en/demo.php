<?php

declare(strict_types=1);

/*
| P02 demo panel (wireframe p02-sign-in, spec 3-01 issue 4). Shown only when
| DEMO_LOGIN_ENABLED=true. Account labels describe the behavior each seeded
| account exists to show; balances change as people use the shared accounts.
*/

return [
    'panel_title' => 'Evaluating this take-home?',
    'panel_description' => 'One click, no password. Shared accounts: balances change as people use them.',
    'admin_hint' => 'Admin console: /admin (separate login, see the README).',
    'expired' => 'This demo account has expired. Register or try a new demo account.',
    'accounts' => [
        'showcase' => 'Showcase customer · real history',
        'demo_customer' => 'Demo customer · $10.00 credit',
        'low_balance' => 'Low balance · $0.01 (see blocking)',
        'new_customer' => 'New customer · $0.00 (empty states)',
    ],
];
