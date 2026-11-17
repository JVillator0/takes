<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-065: an ephemeral demo customer past `demo_expires_at` is logged out on
 * its next request. Lazy on purpose: no scheduler, no hard delete (customers
 * are never deleted). Alias: `customer.demo`.
 */
final class EnsureDemoAccountActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        if ($customer instanceof Customer && $customer->isExpiredDemo()) {
            Auth::guard('customer')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('demo.expired'));
        }

        return $next($request);
    }
}
