<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
| Provider webhooks (spec 5, W-01). Loaded outside every middleware group:
| no session, no cookies, no CSRF, no auth guard. The signature middleware is
| the only gate; a bad signature answers 401 and writes nothing (D-056).
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
