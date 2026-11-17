<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Takes\SweepStaleTakesAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the opportunistic sweeper after a signed-in page load has been sent
 * (D-036): workspace, AI drawer, admin pages and the polling fallback's
 * partial reloads all count. The action throttles itself (one sweep per
 * 2 min), so this costs one cache lock attempt per request at most.
 */
final class SweepStaleTakes
{
    public function __construct(private readonly SweepStaleTakesAction $sweep) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET') || $request->user() === null || ! $response->isSuccessful()) {
            return;
        }

        $this->sweep->execute();
    }
}
