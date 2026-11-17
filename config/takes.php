<?php

declare(strict_types=1);

use App\Support\Money\Nanodollars;

/*
|--------------------------------------------------------------------------
| Takes product settings (spec 3-01, D-050, D-066)
|--------------------------------------------------------------------------
|
| Owned by spec 3-01; specs 4 and 8 only read these keys and spec 9 only sets
| env values. USD env values are decimal strings parsed once here through
| Nanodollars::fromDecimalString (never a float), so every value below is an
| integer (nanodollars or a count), a bool or a string.
|
*/

return [

    // Interpolated into the `account_restricted` denial copy. Required in production.
    'support_email' => env('TAKES_SUPPORT_EMAIL', 'support@example.com'),

    // Takes created per customer per UTC day (`daily_take_cap`).
    'daily_cap_per_customer' => (int) env('TAKES_DAILY_CAP_PER_CUSTOMER', 40),

    // Takes created by all customers per UTC day (`demo_quota_reached`, D-065).
    'daily_cap_global' => (int) env('TAKES_DAILY_CAP_GLOBAL', 300),

    // Welcome credit on registration (D-019), read by spec 4's GrantWelcomeCreditAction.
    'welcome_credit_nanos' => Nanodollars::fromDecimalString((string) env('WELCOME_CREDIT_USD', '1.00'))->toInt(),

    'ai' => [
        'enabled' => (bool) env('AI_SUGGESTIONS_ENABLED', true),

        // Global Anthropic spend per UTC day before `ai_paused`.
        'daily_budget_nanos' => Nanodollars::fromDecimalString((string) env('AI_DAILY_BUDGET_USD', '1.00'))->toInt(),

        // Suggestions per customer per UTC day (`ai_daily_limit`).
        'daily_limit_per_customer' => (int) env('AI_DAILY_LIMIT_PER_CUSTOMER', 30),

        // Route throttle (`rate_limited`), owned by spec 8's route.
        'throttle_per_minute' => (int) env('TAKES_AI_THROTTLE_PER_MINUTE', 6),
    ],

    'demo' => [
        // P02 demo panel with seeded logins (D-045). Off by default; production demo only.
        'enabled' => (bool) env('DEMO_LOGIN_ENABLED', false),

        // Read by spec 4's `throttle:demo` on POST /demo.
        'throttle_per_ip_per_hour' => (int) env('DEMO_LOGIN_THROTTLE_PER_IP', 5),

        // Ephemeral demo customers expire after this many hours (D-065).
        'ttl_hours' => 24,

        // Password of the seeded demo logins; public on purpose (README, P02 panel).
        'password' => env('DEMO_ACCOUNTS_PASSWORD', 'password'),
    ],

];
