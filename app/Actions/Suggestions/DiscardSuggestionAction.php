<?php

declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Enums\Suggestions\SuggestionStatus;
use App\Exceptions\Suggestions\SuggestionNotProposedException;
use App\Models\Customer;
use App\Models\Suggestion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * *Discard* a proposal (spec 8 step 4): `proposed` ⇒ `discarded`. No money
 * moves: the suggestion was settled when it was proposed, and the 2-01
 * disclosure says it is charged even if discarded. Discarding twice is a no-op.
 */
final readonly class DiscardSuggestionAction
{
    /**
     * @throws AuthorizationException when the suggestion belongs to another customer
     * @throws SuggestionNotProposedException when it is pending, applied or failed
     */
    public function execute(Customer $customer, Suggestion $suggestion): Suggestion
    {
        return DB::transaction(function () use ($customer, $suggestion): Suggestion {
            $suggestion = Suggestion::query()->lockForUpdate()->findOrFail($suggestion->id);

            if ($suggestion->customer_id !== $customer->id) {
                throw new AuthorizationException;
            }

            if ($suggestion->status === SuggestionStatus::Discarded) {
                return $suggestion;
            }

            if ($suggestion->status !== SuggestionStatus::Proposed) {
                throw SuggestionNotProposedException::for($suggestion->id, $suggestion->status);
            }

            $suggestion->forceFill(['status' => SuggestionStatus::Discarded])->save();

            return $suggestion;
        });
    }
}
