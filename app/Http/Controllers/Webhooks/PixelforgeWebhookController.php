<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Webhooks\HandlePixelforgeWebhookAction;
use App\DTOs\Webhooks\PixelforgeWebhookDTO;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /webhooks/pixelforge` (spec 5). The signature was verified on the raw
 * body by VerifyPixelforgeSignature. Every verified delivery the handler
 * records answers 2xx so Pixelforge stops retrying (W-10); a body that is not
 * a readable event answers 400 and writes nothing (W-04).
 */
final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, HandlePixelforgeWebhookAction $handle): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $attempt = filter_var($request->header('Pixelforge-Delivery-Attempt'), FILTER_VALIDATE_INT);

        try {
            $event = PixelforgeWebhookDTO::fromArray(is_array($data) ? $data : [], is_int($attempt) ? $attempt : 1);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        return response()->json(['status' => $handle->execute($event)->value]);
    }
}
