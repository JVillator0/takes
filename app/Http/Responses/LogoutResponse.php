<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * Fortify's sign-out, with one allow-listed destination: the read-only
 * showcase notice (D-072) signs the shared account out and goes straight to
 * the P02 demo panel or Register (`to=login|register`), without the landing
 * page in between. Any other value, or none, goes home as before.
 */
final class LogoutResponse implements LogoutResponseContract
{
    /** @var array<string, string> `to` value => route name */
    private const array TARGETS = [
        'login' => 'login',
        'register' => 'register',
    ];

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        // Strict: an array (to[]=login) is not a target, never a string cast.
        $route = self::TARGETS[$request->string('to', strict: true)->toString()] ?? null;

        return redirect($route === null ? '/' : route($route));
    }
}
