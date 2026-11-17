<?php

declare(strict_types=1);

namespace App\Actions\Takes;

use App\Actions\Billing\OpenUsageAction;
use App\DTOs\Takes\CreateTakeDTO;
use App\DTOs\Takes\EstimateTakeDTO;
use App\Enums\Auth\DenialReason;
use App\Enums\Billing\Provider;
use App\Events\TakeUpdated;
use App\Exceptions\Auth\ActionDeniedException;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Projects\ProjectArchivedException;
use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Exceptions\Takes\ServiceNotConfiguredException;
use App\Jobs\ReconcileTakeJob;
use App\Jobs\SubmitTakeJob;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;
use App\Queries\Takes\EstimateTakeQuery;
use App\Queries\Takes\ResolveTakeImageReferencesQuery;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a take and holds its maximum price (spec 4 "Create take").
 *
 * 1. Prices the real prompt (fresh GET /v1/pricing facts, config fallback),
 *    before any lock: the client estimate is display only.
 * 2. In one transaction, under the customer lock (the take does not exist
 *    yet, so the lock order take → customer holds, spec 3-03 §6):
 *    a replayed idempotency key returns the existing take (no new hold, no
 *    new job, D-033); otherwise TakePolicy::create (restriction, caps,
 *    `available ≥ maxCharge`, D-050), the `pending` take and its usage
 *    with the hold (OpenUsageAction, D-024), which re-checks credits.
 * 3. After commit: SubmitTakeJob, the reconcile chain (a lost submit job
 *    still gets found) and the live update.
 *
 * Callable inside an outer transaction (spec 8 apply, with a deterministic
 * key): pricing then comes from the cache, never over HTTP (M-16), and the
 * jobs wait for the outer commit.
 */
final readonly class CreateTakeAction
{
    public function __construct(
        private PixelforgeClient $client,
        private EstimateTakeQuery $estimateTake,
        private OpenUsageAction $openUsage,
        private ResolveTakeImageReferencesQuery $resolveReferences,
    ) {}

    /**
     * @throws ActionDeniedException with the DenialReason (insufficient credits, caps, restriction)
     * @throws AuthorizationException when the project or the parent take belongs to someone else
     * @throws ServiceNotConfiguredException when the image service has no API key
     * @throws ProjectArchivedException when the project is archived
     * @throws BaseImageUnavailableException when a reference is one of the customer's take images with no usable URL any more
     */
    public function execute(Customer $customer, Project $project, CreateTakeDTO $data): Take
    {
        if ($project->customer_id !== $customer->id) {
            throw new AuthorizationException;
        }

        if (! $this->client->isConfigured()) {
            throw new ServiceNotConfiguredException;
        }

        $quote = $this->estimateTake->execute(
            EstimateTakeDTO::forPrompt($data->prompt, $data->size, $data->quality, $data->referenceImages),
        );

        [$take, $created] = DB::transaction(function () use ($customer, $project, $data, $quote): array {
            // Lock order take → customer (M-10). A parent take (remix, Try
            // again, AI apply) is locked first with FOR KEY SHARE: exactly what
            // the lineage FK insert needs, it still waits for a parent being
            // settled (FOR UPDATE, take → customer), and it is compatible with
            // the key-share CreateSuggestionAction T1 takes on the same take
            // after the customer, so no lock cycle exists either way.
            if ($data->parentTakeId !== null
                && Take::query()->whereKey($data->parentTakeId)->where('customer_id', $customer->id)->lock('for key share')->first() === null) {
                throw new AuthorizationException;
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $existing = Take::query()
                ->where('customer_id', $customer->id)
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            // ArchiveProjectAction takes the same customer lock, so this read
            // cannot race an archive (composer or AI apply).
            if (Project::query()->whereKey($project->id)->whereNotNull('archived_at')->exists()) {
                throw ProjectArchivedException::for($project->id);
            }

            // References to the customer's own take images are short-lived
            // URLs (a remix, Try again, a kept AI reference): re-issue them, or
            // refuse before anything is held. The count, and so the price, is unchanged.
            $references = $this->resolveReferences->execute($customer, $data->referenceImages);

            // The policy again, on the customer as it is under the lock (D-015).
            $decision = Gate::forUser($customer)->inspect('create', [Take::class, $quote->estimate]);

            if ($decision->denied()) {
                $code = $decision->code();

                throw new ActionDeniedException(
                    (is_string($code) ? DenialReason::tryFrom($code) : null) ?? DenialReason::AccountRestricted,
                );
            }

            // A new take is `pending` by default (the model's attribute
            // default); status changes only in the transition actions (M-11).
            $take = new Take;
            $take->forceFill([
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'parent_take_id' => $data->parentTakeId,
                'suggestion_id' => $data->suggestionId,
                'source' => $data->resolvedSource(),
                'idempotency_key' => $data->idempotencyKey,
                'prompt' => $data->prompt,
                'size' => $data->size,
                'quality' => $data->quality,
                'reference_images' => $references,
            ])->save();

            try {
                $this->openUsage->execute($take, Provider::Pixelforge, $quote->estimate, $quote->rates);
            } catch (InsufficientCreditsException) {
                // Rolls back the take too: no take, no usage, no hold.
                throw new ActionDeniedException(DenialReason::InsufficientCredits);
            }

            return [$take, true];
        });

        if ($created) {
            DB::afterCommit(function () use ($take): void {
                SubmitTakeJob::dispatch($take->id);
                ReconcileTakeJob::start($take->id);
                TakeUpdated::dispatch($take);
            });
        }

        return $take;
    }
}
