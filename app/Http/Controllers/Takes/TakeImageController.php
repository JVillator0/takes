<?php

declare(strict_types=1);

namespace App\Http\Controllers\Takes;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Take;
use App\Queries\Takes\ListTakeNumbersQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /takes/{take}/image` (spec 4): streams the persisted copy from the
 * private default disk, owner only. The fallback when a temporary URL is
 * unavailable, and the Download action (`?download=1`). Another
 * customer's take, an unknown id and a take with no stored image are the
 * same 404.
 */
final class TakeImageController extends Controller
{
    public function __invoke(Request $request, Take $take, ListTakeNumbersQuery $numbers): StreamedResponse
    {
        $customer = $request->user();
        assert($customer instanceof Customer);

        Gate::forUser($customer)->authorize('view', $take);

        abort_if($take->image_path === null || ! Storage::exists($take->image_path), 404);

        $number = $numbers->execute([$take->id])[$take->id] ?? null;
        $name = 'take-'.($number ?? $take->id).'.png';
        $headers = ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=900'];

        return $request->boolean('download')
            ? Storage::download($take->image_path, $name, $headers)
            : Storage::response($take->image_path, $name, $headers);
    }
}
