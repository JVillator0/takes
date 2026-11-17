<?php

declare(strict_types=1);

namespace App\Support\Takes;

use App\Actions\Takes\CreateTakeAction;
use App\Contracts\Takes\CreatesSuggestedTake;
use App\DTOs\Suggestions\SuggestedTakeDTO;
use App\DTOs\Takes\CreateTakeDTO;
use App\Exceptions\Auth\ActionDeniedException;
use App\Exceptions\Projects\ProjectArchivedException;
use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Exceptions\Takes\ServiceNotConfiguredException;
use App\Exceptions\Takes\TakeCreationUnavailableException;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;

/**
 * Spec 8's seam bound to spec 4's pipeline: applying an AI suggestion creates
 * the take exactly like the composer does (CreateTakeAction): the estimate
 * recomputed from the real prompt, TakePolicy::create re-checked under the
 * customer lock, the hold opened, then the submit job and the reconcile
 * chain after the outer commit. Called inside ApplySuggestionAction's
 * transaction (lock order suggestion → customer, M-10); pricing comes from
 * the cache there, never over HTTP (M-16).
 *
 * Kept references that point at the customer's take images are re-issued
 * by CreateTakeAction (ResolveTakeImageReferencesQuery), or the apply is
 * refused with BaseImageUnavailableException before anything is held.
 *
 * Idempotent: the key is uuid v5 of the suggestion id (ApplySuggestionAction),
 * so a replay returns the same take with no second hold, and
 * `takes(suggestion_id)` is unique as the backstop (M-18).
 */
final readonly class SuggestedTakeCreator implements CreatesSuggestedTake
{
    public function __construct(private CreateTakeAction $createTake) {}

    /**
     * @throws BaseImageUnavailableException when a kept reference is one of the customer's take images with no usable URL
     * @throws ProjectArchivedException when the project was archived meanwhile; passed on for the apply route to answer 409
     */
    public function create(Customer $customer, Project $project, SuggestedTakeDTO $take): Take
    {
        try {
            return $this->createTake->execute($customer, $project, new CreateTakeDTO(
                prompt: $take->prompt,
                size: $take->size,
                quality: $take->quality,
                referenceImages: $take->referenceImages,
                idempotencyKey: $take->idempotencyKey,
                parentTakeId: $take->parentTakeId,
                source: $take->source,
                suggestionId: $take->suggestionId,
            ));
        } catch (ActionDeniedException $denied) {
            throw new SuggestionDeniedException($denied->reason);
        } catch (ServiceNotConfiguredException $exception) {
            throw TakeCreationUnavailableException::serviceNotConfigured($exception);
        }
    }
}
