<?php

declare(strict_types=1);

namespace App\Contracts\Takes;

use App\DTOs\Suggestions\SuggestedTakeDTO;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Exceptions\Takes\TakeCreationUnavailableException;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;

/**
 * The seam between "Describe a change" (spec 8) and take creation (spec 4).
 * Spec 4 binds an adapter over CreateTakeAction; until then
 * UnavailableSuggestedTakeCreator refuses, so apply never creates a take
 * outside the take pipeline (estimate, hold, submit job, reconcile).
 *
 * Contract for the implementation:
 * - callable inside the caller's transaction, which holds the suggestion
 *   lock (lock order suggestion → customer, money rule M-10); no HTTP call
 *   inside it (M-16): pricing comes from the cache;
 * - authorizes the new take exactly like the composer: TakePolicy::create
 *   (restriction, daily caps, credits against the recomputed estimate),
 *   re-checked under the customer lock, denials as SuggestionDeniedException
 *   with the DenialReason (apply itself only checks ownership);
 * - recomputes the estimate from the real prompt and opens the hold;
 * - idempotent on (customer, idempotency key): a replay returns the
 *   existing take, with no second hold.
 */
interface CreatesSuggestedTake
{
    /**
     * @throws SuggestionDeniedException when a policy or the hold denies it (insufficient credits, caps)
     * @throws TakeCreationUnavailableException when spec 4 is not bound yet
     */
    public function create(Customer $customer, Project $project, SuggestedTakeDTO $take): Take;
}
