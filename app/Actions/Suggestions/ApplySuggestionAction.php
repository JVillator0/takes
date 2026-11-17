<?php

declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Contracts\Takes\CreatesSuggestedTake;
use App\DTOs\Suggestions\SuggestedTakeDTO;
use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Enums\Suggestions\SuggestionStatus;
use App\Exceptions\Projects\ProjectArchivedException;
use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Exceptions\Suggestions\SuggestionNotProposedException;
use App\Exceptions\Takes\TakeCreationUnavailableException;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Support\Suggestions\ProposalReferences;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * *Generate take* on a proposal (spec 8 step 4). One transaction, lock order
 * suggestion → customer (the take creator locks the customer, M-10):
 * require `proposed`, create the take through spec 4's pipeline (source
 * `ai_change`, `parent_take_id` = base take, `suggestion_id`, the references
 * from ProposalReferences, spec 4's remix source order), which
 * recomputes the estimate from the real prompt and opens the hold, then set
 * `applied` and `resulting_take_id`.
 *
 * A second apply replays: the locked suggestion is already `applied` and
 * returns its take; the take's idempotency key is uuid v5 of the suggestion
 * id (D-033) and `takes(suggestion_id)` is unique, so no path can create a
 * second take (money rule M-18).
 */
final readonly class ApplySuggestionAction
{
    /** uuid v5 namespace of the apply idempotency keys. Never change it: replays depend on it. */
    public const string IDEMPOTENCY_NAMESPACE = 'd015a16c-fb9d-4fd1-9709-1d8e6abd554f';

    public function __construct(private CreatesSuggestedTake $takes) {}

    /**
     * @throws AuthorizationException when the suggestion belongs to another customer
     * @throws SuggestionNotProposedException when it is not `proposed` (nor already `applied`)
     * @throws BaseImageUnavailableException when the base image was asked for and has no usable URL
     * @throws SuggestionDeniedException when the new take is denied (credits, caps)
     * @throws TakeCreationUnavailableException until spec 4 binds the take creator
     * @throws ProjectArchivedException when the project was archived meanwhile
     */
    public function execute(Customer $customer, Suggestion $suggestion): Take
    {
        return DB::transaction(function () use ($customer, $suggestion): Take {
            $suggestion = Suggestion::query()->lockForUpdate()->findOrFail($suggestion->id);

            if ($suggestion->customer_id !== $customer->id) {
                throw new AuthorizationException;
            }

            if ($suggestion->status === SuggestionStatus::Applied && $suggestion->resulting_take_id !== null) {
                return Take::query()->findOrFail($suggestion->resulting_take_id);
            }

            if ($suggestion->status !== SuggestionStatus::Proposed || $suggestion->proposal === null) {
                throw SuggestionNotProposedException::for($suggestion->id, $suggestion->status);
            }

            /** @var array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string} $stored */
            $stored = $suggestion->proposal;
            $proposal = TakeChangeProposalDTO::fromArray($stored);
            $base = $suggestion->baseTake()->firstOrFail();

            $take = $this->takes->create($customer, $suggestion->project()->firstOrFail(), new SuggestedTakeDTO(
                prompt: $proposal->prompt,
                size: $proposal->size,
                quality: $proposal->quality,
                referenceImages: ProposalReferences::urls($proposal, $base),
                idempotencyKey: self::idempotencyKey($suggestion->id),
                parentTakeId: $base->id,
                suggestionId: $suggestion->id,
            ));

            $suggestion->forceFill([
                'status' => SuggestionStatus::Applied,
                'resulting_take_id' => $take->id,
            ])->save();

            return $take;
        });
    }

    private static function idempotencyKey(string $suggestionId): string
    {
        return Uuid::uuid5(self::IDEMPOTENCY_NAMESPACE, 'suggestion:'.$suggestionId)->toString();
    }
}
