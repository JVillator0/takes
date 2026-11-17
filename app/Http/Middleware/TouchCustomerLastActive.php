<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Auth\TouchCustomerLastActiveAction;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Customers › Last active (spec 7) fresh: compares the cached value in memory first, so a request writes
 * at most once every five minutes per customer.
 */
final readonly class TouchCustomerLastActive
{
    public function __construct(private TouchCustomerLastActiveAction $touch) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');

        if ($customer instanceof Customer) {
            $this->touch->execute($customer);
        }

        return $next($request);
    }
}
