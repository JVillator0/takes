<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin console (spec 7)
|--------------------------------------------------------------------------
|
| The seeded admins (AdminUsersSeeder) and the environment badge. A seeded
| account is created only when both its email and password are set, so a
| production deploy without these env values seeds no login at all.
|
*/

return [

    // First super admin; signs in with an authenticator app (MFA required, D-013).
    'super_admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Read-only, MFA-exempt evaluator login (D-013, D-045). Shown on /admin/login while `takes.demo.enabled`.
    'auditor' => [
        'name' => env('ADMIN_AUDITOR_NAME', 'Auditor'),
        'email' => env('ADMIN_AUDITOR_EMAIL'),
        'password' => env('ADMIN_AUDITOR_PASSWORD'),
    ],

    // Badge and border on every console page: LOCAL (sky), PRODUCTION (red). Defaults to APP_ENV.
    'environment_label' => env('ADMIN_ENVIRONMENT_LABEL'),

];
