<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-032: the public app runs on the `customer` guard while `web` stays the
 * default (admins, D-012). Switching the default guard for the request makes
 * `$request->user()`, `Gate::inspect()`, FormRequest `authorize()`, policies
 * and Inertia shared props resolve the Customer. Alias: `customer.guard`.
 */
final class UseCustomerGuard
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('customer');

        return $next($request);
    }
}
