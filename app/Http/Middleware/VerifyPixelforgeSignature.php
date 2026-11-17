<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Pixelforge\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any Pixelforge webhook whose signature does not verify against the
 * raw body (W-02). A rejected request answers 401 before the handler runs,
 * so nothing is written: no event, no delivery, no outcome (D-056, W-03).
 */
final class VerifyPixelforgeSignature
{
    public const string HEADER = 'Pixelforge-Signature';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('pixelforge.webhook_secret');

        $failure = WebhookSignature::verify(
            header: $request->header(self::HEADER),
            rawBody: $request->getContent(),
            secret: is_string($secret) ? $secret : null,
            toleranceSeconds: config()->integer('pixelforge.webhook_tolerance_seconds'),
            now: now()->getTimestamp(),
        );

        if ($failure !== null) {
            Log::warning('Pixelforge webhook rejected: invalid signature.', [
                'reason' => $failure->value,
                'event_id' => $request->header('Pixelforge-Event-Id'),
                'attempt' => $request->header('Pixelforge-Delivery-Attempt'),
            ]);

            return response()->json(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
