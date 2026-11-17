<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Takes\SweepStaleTakesAction;
use App\Jobs\SweepStaleTakesJob;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Queues the opportunistic sweeper after a signed-in page load has been sent
 * (D-036): customer pages (workspace, AI drawer, billing), every
 * authenticated admin console page, and the polling fallback's partial
 * reloads all count. The throttle lock (one sweep per 2 min) is taken here,
 * so a request costs one lock attempt and at most one job; the sweep itself
 * runs as a queued job, outside the request and the console context.
 */
final class SweepStaleTakes
{
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

        if (Cache::lock(SweepStaleTakesAction::LOCK, SweepStaleTakesAction::THROTTLE_SECONDS)->get()) {
            SweepStaleTakesJob::dispatch();
        }
    }
}
