<?php

declare(strict_types=1);

namespace App\Http\Controllers\Takes;

use App\Actions\Takes\CreateTakeAction;
use App\DTOs\Takes\CreateTakeDTO;
use App\Exceptions\Auth\ActionDeniedException;
use App\Exceptions\Takes\ServiceNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Takes\StoreTakeRequest;
use App\Http\Resources\TakeDetailResource;
use App\Http\Responses\DenialResponse;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;
use App\Queries\Takes\GetTakeDetailQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creating a take (spec 4 "Create take") and reading one as JSON.
 */
final class TakeController extends Controller
{
    /**
     * A replayed idempotency key returns to the workspace with the existing
     * take and nothing new (D-033). Denials come back as `{code, message}`
     * validation-style errors (D-050).
     */
    public function store(StoreTakeRequest $request, Project $project, CreateTakeAction $createTake): JsonResponse|RedirectResponse
    {
        try {
            $createTake->execute($request->customer(), $project, CreateTakeDTO::fromRequest($request));
        } catch (ActionDeniedException $denied) {
            return DenialResponse::make($request, $denied->reason);
        } catch (ServiceNotConfiguredException) {
            throw ValidationException::withMessages(['prompt' => __('takes.service.not_configured')]);
        }

        return back(303);
    }

    /**
     * `GET /takes/{take}`: the drawer's data for non-Inertia callers. Another
     * customer's take and an unknown id are the same 404 (spec 2-02).
     */
    public function show(Request $request, Take $take, GetTakeDetailQuery $takeDetail): TakeDetailResource
    {
        $customer = $request->user();
        assert($customer instanceof Customer);

        Gate::forUser($customer)->authorize('view', $take);

        return new TakeDetailResource($takeDetail->execute($take));
    }
}
