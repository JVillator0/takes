<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Customers have no email verification (D-030), and two-factor and passkeys
| are off: both are tied to the admin `users` table. Admin MFA is spec 7.
| Customers are never hard-deleted, so there is no account deletion either.
*/

it('registers no verification, two-factor, passkey or account-deletion routes', function (string $name): void {
    expect(Route::has($name))->toBeFalse();
})->with([
    'verification.notice',
    'verification.verify',
    'verification.send',
    'two-factor.login',
    'two-factor.enable',
    'passkeys.store',
    'passkeys.login',
    'profile.destroy',
]);
