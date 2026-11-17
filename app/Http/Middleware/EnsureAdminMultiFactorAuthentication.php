<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * The console's "MFA required" middleware (spec 7 issue 1 spike, D-013).
 *
 * Filament evaluates the panel's `isRequired` closure once, when it registers the routes (no admin signed in yet),
 * to decide whether to attach this middleware; its own middleware then redirects anyone without an authenticator
 * app. This one evaluates the same closure again per request, now with the signed-in admin, so the panel closure
 * stays the single lever and the auditor passes without setting up an app.
 */
final readonly class EnsureAdminMultiFactorAuthentication
{
    public function __construct(private EnsureMultiFactorAuthenticationIsEnabled $ensureEnabled) {}

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (Filament::getCurrentPanel()?->isMultiFactorAuthenticationRequired() !== true) {
            return $next($request);
        }

        return $this->ensureEnabled->handle($request, $next);
    }
}
