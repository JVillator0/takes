<?php

declare(strict_types=1);

namespace App\Http\Controllers\Suggestions;

use App\Actions\Suggestions\ApplySuggestionAction;
use App\Actions\Suggestions\CreateSuggestionAction;
use App\Actions\Suggestions\DiscardSuggestionAction;
use App\DTOs\Suggestions\CreateSuggestionDTO;
use App\Enums\Auth\DenialReason;
use App\Exceptions\Suggestions\BaseTakeNotReadyException;
use App\Exceptions\Suggestions\SuggestionAlreadyPendingException;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Exceptions\Suggestions\SuggestionNotProposedException;
use App\Exceptions\Takes\TakeCreationUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suggestions\StoreSuggestionRequest;
use App\Http\Resources\SuggestionResource;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Queries\Suggestions\GetSuggestionDetailsQuery;
use App\Queries\Suggestions\GetSuggestionQuoteQuery;
use App\Support\Money\RoundingMode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Describe a change" endpoints for the P07 panel (spec 8). JSON for the
 * panel's standalone requests (Inertia `useHttp`); denials carry the
 * DenialReason code and its 2-01 copy, never as a failure (D-050).
 */
final class SuggestionController extends Controller
{
    /** The most a suggestion on this take can hold, and whether one is allowed now. */
    public function quote(Request $request, Take $take, GetSuggestionQuoteQuery $quote): JsonResponse
    {
        $customer = self::customer($request);
        Gate::forUser($customer)->authorize('view', $take);

        $estimate = $quote->execute($take);
        $decision = Gate::forUser($customer)->inspect('create', [Suggestion::class, $estimate]);
        $reason = $decision->denied() && is_string($decision->code()) ? DenialReason::tryFrom($decision->code()) : null;

        return response()->json([
            'quote' => __('suggestions.quote', ['amount' => $estimate->maxCharge->format(4, RoundingMode::Ceil)]),
            'denial' => $reason === null ? null : ['reason' => $reason->value, 'message' => $reason->message()],
        ]);
    }

    public function store(StoreSuggestionRequest $request, Take $take, CreateSuggestionAction $createSuggestion, GetSuggestionDetailsQuery $details): JsonResponse
    {
        $customer = $request->customer();
        Gate::forUser($customer)->authorize('view', $take);

        try {
            $suggestion = $createSuggestion->execute($customer, $take, CreateSuggestionDTO::fromRequest($request));
        } catch (SuggestionDeniedException $exception) {
            return self::denied($exception->reason);
        } catch (SuggestionAlreadyPendingException) {
            return self::rejected(409, 'already_pending', __('suggestions.already_pending'));
        } catch (BaseTakeNotReadyException) {
            return self::rejected(422, 'base_not_ready', __('suggestions.base_not_ready'));
        }

        return (new SuggestionResource($details->execute($suggestion, $customer)))->response();
    }

    public function show(Request $request, Suggestion $suggestion, GetSuggestionDetailsQuery $details): JsonResponse
    {
        $customer = self::customer($request);
        Gate::forUser($customer)->authorize('view', $suggestion);

        return (new SuggestionResource($details->execute($suggestion, $customer)))->response();
    }

    /** *Generate take*: the new take's id; a second apply returns the same take. */
    public function apply(Request $request, Suggestion $suggestion, ApplySuggestionAction $applySuggestion): JsonResponse
    {
        $customer = self::customer($request);
        Gate::forUser($customer)->authorize('view', $suggestion);

        try {
            $take = $applySuggestion->execute($customer, $suggestion);
        } catch (SuggestionDeniedException $exception) {
            return self::denied($exception->reason);
        } catch (SuggestionNotProposedException) {
            return self::rejected(409, 'not_proposed', __('suggestions.failed'));
        } catch (TakeCreationUnavailableException) {
            return self::rejected(503, 'apply_unavailable', __('suggestions.apply_unavailable'));
        }

        return response()->json(['takeId' => $take->id, 'message' => __('suggestions.applied')]);
    }

    public function discard(Request $request, Suggestion $suggestion, DiscardSuggestionAction $discardSuggestion, GetSuggestionDetailsQuery $details): JsonResponse
    {
        $customer = self::customer($request);
        Gate::forUser($customer)->authorize('view', $suggestion);

        try {
            $suggestion = $discardSuggestion->execute($customer, $suggestion);
        } catch (SuggestionNotProposedException) {
            return self::rejected(409, 'not_proposed', __('suggestions.failed'));
        }

        return (new SuggestionResource($details->execute($suggestion, $customer)))->response();
    }

    private static function customer(Request $request): Customer
    {
        $customer = $request->user();

        return $customer instanceof Customer ? $customer : throw new AuthenticationException(guards: ['customer']);
    }

    private static function denied(DenialReason $reason): JsonResponse
    {
        return self::rejected(403, $reason->value, $reason->message());
    }

    private static function rejected(int $status, string $reason, string $message): JsonResponse
    {
        return response()->json(['reason' => $reason, 'message' => $message], $status);
    }
}
