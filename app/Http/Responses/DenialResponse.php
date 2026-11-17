<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\DTOs\Auth\AbilityDTO;
use App\Enums\Auth\DenialReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * A denial with its `DenialReason` code (D-050), rendered like a validation
 * error (spec 4 "Create take"): an Inertia visit goes back with the
 * `denial` error and flash (`{allowed, reason, message}`, the same shape as
 * the policy renderer in bootstrap/app.php); a JSON caller gets
 * `{code, message}` with `$jsonStatus` (422, or 429 for `rate_limited`).
 * The message is the 2-01 copy from lang/en/denials.php (D-057).
 */
final class DenialResponse
{
    public static function make(Request $request, DenialReason $reason, int $jsonStatus = 422): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia') === null && $request->expectsJson()) {
            return new JsonResponse(['code' => $reason->value, 'message' => $reason->message()], $jsonStatus);
        }

        Inertia::flash('denial', (new AbilityDTO(allowed: false, reason: $reason))->toArray());

        return back($request->header('X-Inertia') !== null ? 303 : 302)->withErrors(['denial' => $reason->message()]);
    }
}
