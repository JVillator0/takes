<?php

declare(strict_types=1);

namespace App\Http\Controllers\Takes;

use App\Actions\Takes\CancelTakeAction;
use App\Exceptions\Takes\TakeCannotBeCancelledException;
use App\Http\Controllers\Controller;
use App\Http\Responses\DenialResponse;
use App\Models\Customer;
use App\Models\Take;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /takes/{take}/cancel` (spec 4 "Cancel", D-044): only for a take
 * waiting for capacity; otherwise 422 `cannot_cancel`. Not the owner ⇒ 404.
 */
final class CancelTakeController extends Controller
{
    public function __invoke(Request $request, Take $take, CancelTakeAction $cancelTake): JsonResponse|RedirectResponse
    {
        $customer = $request->user();
        assert($customer instanceof Customer);

        Gate::forUser($customer)->authorize('view', $take);

        try {
            $cancelTake->execute($take);
        } catch (TakeCannotBeCancelledException) {
            return DenialResponse::rejected($request, TakeCannotBeCancelledException::CODE, __('takes.cancel_unavailable'), 'cancel');
        }

        return back(303);
    }
}
