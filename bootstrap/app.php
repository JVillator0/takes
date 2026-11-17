<?php

declare(strict_types=1);

use App\DTOs\Auth\AbilityDTO;
use App\Enums\Auth\DenialReason;
use App\Http\Middleware\EnsureDemoAccountActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SweepStaleTakes;
use App\Http\Middleware\TouchCustomerLastActive;
use App\Http\Middleware\UseCustomerGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Provider webhooks sit outside the `web` group (spec 5, W-01).
        then: function (): void {
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    // Private customer channels (spec 3-01), served by Reverb (spec 5). The
    // channel declares `guards => ['customer']`; the route authenticates on
    // the customer guard (D-032), so an admin session never authorizes.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'customer.guard', 'auth:customer']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SweepStaleTakes::class,
        ]);

        $middleware->alias([
            'customer.guard' => UseCustomerGuard::class,
            'customer.demo' => EnsureDemoAccountActive::class,
            'customer.active' => TouchCustomerLastActive::class,
        ]);

        // Every customer route (D-032): customer guard as the request default,
        // authenticated on it, expired demo customers logged out (D-065), and
        // Last active refreshed at most every 5 minutes (spec 7).
        $middleware->group('customer', [
            'customer.guard',
            'auth:customer',
            'customer.demo',
            'customer.active',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A policy denial with a DenialReason code goes back to the Inertia
        // page as a `denial` flash ({ allowed, reason, message }), spec 3-01.
        // Laravel wraps the AuthorizationException before render callbacks run.
        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            $authorization = $exception->getPrevious();

            if (! $authorization instanceof AuthorizationException || ! $request->header('X-Inertia')) {
                return null;
            }

            // A FormRequest returning `false` throws without a response object.
            $code = optional($authorization->response())->code();
            $reason = is_string($code) ? DenialReason::tryFrom($code) : null;

            if ($reason === null) {
                return null;
            }

            Inertia::flash('denial', (new AbilityDTO(allowed: false, reason: $reason))->toArray());

            // Also an error, so Inertia runs the form's error path (no reset-on-success).
            return back(303)->withErrors(['denial' => $reason->message()]);
        });
    })->create();
