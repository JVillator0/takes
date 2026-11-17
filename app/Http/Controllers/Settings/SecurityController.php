<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\UpdateCustomerPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer password settings. Two-factor and passkeys are disabled for
 * customers (config/fortify.php): both are tied to the admin `users` table.
 */
final class SecurityController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Security', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(PasswordUpdateRequest $request, UpdateCustomerPasswordAction $updatePassword): RedirectResponse
    {
        $updatePassword->execute($request->customer(), $request->string('password')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
