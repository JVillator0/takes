<?php

declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Billing\SettleUsageAction;
use App\Actions\Billing\VoidUsageAction;
use App\Ai\Agents\TakeChangeAgent;
use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Suggestions\CreateSuggestionDTO;
use App\DTOs\Suggestions\TakeChangeInputDTO;
use App\DTOs\Suggestions\TakeChangeResultDTO;
use App\Enums\Auth\DenialReason;
use App\Enums\Billing\Provider;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Suggestions\BaseTakeNotReadyException;
use App\Exceptions\Suggestions\RequestKeyReusedException;
use App\Exceptions\Suggestions\SuggestionAlreadyPendingException;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Pricing\AiPricing;
use App\Support\Pricing\SuggestionEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * "Describe a change" (spec 8 step 2): two transactions with the model call
 * between them, outside any lock (money rule M-16).
 *
 * Expiry: first, void this customer's pending suggestions past expires_at
 *     (ExpireSuggestionAction), so a crash between T1 and T2 never blocks a
 *     take: the customer's next request voids the stale ones and releases
 *     their holds (spec 5's sweeper does the same for every customer).
 * T1  serialize on the AI budget lock, lock the customer; a replayed
 *     request_key returns the existing suggestion (no call, no hold, D-033;
 *     the same key on another base take is a 409); reject a second unexpired
 *     pending suggestion on the same base take; re-check
 *     SuggestionPolicy::create under the lock (D-015); insert the `pending`
 *     suggestion and open the usage with the per-request hold (D-024, D-055).
 * Call ProposeTakeChangeAction: never throws for a provider problem.
 * T2  lock the suggestion, then (through the money actions) the customer:
 *     proposal ⇒ settle the exact usage + 25%, status `proposed`; anything
 *     else ⇒ void (failed = free, M-13), status `failed`. A suggestion the
 *     sweeper already expired (spec 5) is left alone: its usage is voided.
 *
 * Settle and void happen only here and in the sweeper's expiry (M-12).
 */
final readonly class CreateSuggestionAction
{
    /** A suggestion still `pending` after this long is expired by the spec 5 sweeper. */
    public const int PENDING_TTL_SECONDS = 120;

    /**
     * pg_advisory_xact_lock key serializing T1 across customers, so the
     * global budget check (settled cost + open max cost, D-050) cannot be
     * passed by two concurrent requests at once. Taken before the customer
     * lock; nothing else takes it, so it adds no lock-order cycle.
     */
    public const int BUDGET_LOCK_KEY = 8_008_001;

    public function __construct(
        private OpenUsageAction $openUsage,
        private SettleUsageAction $settleUsage,
        private VoidUsageAction $voidUsage,
        private ProposeTakeChangeAction $proposeTakeChange,
        private SuggestionEstimator $estimator,
        private AiPricing $pricing,
        private ExpireSuggestionAction $expireSuggestion,
    ) {}

    /**
     * @throws AuthorizationException when the base take belongs to another customer
     * @throws BaseTakeNotReadyException when the base take has not succeeded
     * @throws SuggestionAlreadyPendingException when the base take already has a pending suggestion
     * @throws RequestKeyReusedException when the request key was used for another base take
     * @throws SuggestionDeniedException when the policy or the hold denies it
     */
    public function execute(Customer $customer, Take $baseTake, CreateSuggestionDTO $request): Suggestion
    {
        if ($baseTake->customer_id !== $customer->id) {
            throw new AuthorizationException;
        }

        $input = TakeChangeInputDTO::forTake($baseTake, $request->instruction);
        $rates = new RatesSnapshotDTO($this->pricing->rates(Provider::Anthropic, TakeChangeAgent::MODEL));
        $estimate = $this->estimator->estimate((new TakeChangeAgent)->fixedRequestText().$input->toMessage(), $rates->toArray());

        $this->expireStalePending($customer);

        [$suggestion, $usage] = $this->open($customer, $baseTake, $request, $estimate, $rates);

        if ($usage === null) {
            return $suggestion;
        }

        $result = $this->proposeTakeChange->execute($input);

        return $this->close($suggestion, $result, $rates);
    }

    /**
     * T1. Returns the suggestion and its new usage, or a replayed suggestion
     * and null (nothing to call).
     *
     * @return array{0: Suggestion, 1: UsageRecord|null}
     */
    private function open(Customer $customer, Take $baseTake, CreateSuggestionDTO $request, EstimateDTO $estimate, RatesSnapshotDTO $rates): array
    {
        return DB::transaction(function () use ($customer, $baseTake, $request, $estimate, $rates): array {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::BUDGET_LOCK_KEY]);

            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $existing = Suggestion::query()
                ->where('customer_id', $customer->id)
                ->where('request_key', $request->requestKey)
                ->first();

            if ($existing !== null) {
                return $existing->base_take_id === $baseTake->id
                    ? [$existing, null]
                    : throw RequestKeyReusedException::for($request->requestKey);
            }

            if ($baseTake->status !== TakeStatus::Succeeded) {
                throw BaseTakeNotReadyException::for($baseTake->id);
            }

            // A pending suggestion past expires_at no longer blocks (expireStalePending
            // voids it before T1; one that expired since is voided on the next request).
            $pending = Suggestion::query()
                ->where('base_take_id', $baseTake->id)
                ->where('status', SuggestionStatus::Pending->value)
                ->where('expires_at', '>', now())
                ->exists();

            if ($pending) {
                throw SuggestionAlreadyPendingException::forTake($baseTake->id);
            }

            // The policy again, on the customer and the AI spend as they are under the locks (D-015).
            $decision = Gate::forUser($customer)->inspect('create', [Suggestion::class, $estimate]);

            if ($decision->denied()) {
                $code = $decision->code();

                throw new SuggestionDeniedException((is_string($code) ? DenialReason::tryFrom($code) : null) ?? DenialReason::AccountRestricted);
            }

            $suggestion = new Suggestion;
            $suggestion->forceFill([
                'customer_id' => $customer->id,
                'project_id' => $baseTake->project_id,
                'base_take_id' => $baseTake->id,
                'instruction' => $request->instruction,
                'request_key' => $request->requestKey,
                'status' => SuggestionStatus::Pending,
                'model' => TakeChangeAgent::MODEL,
                'expires_at' => now()->addSeconds(self::PENDING_TTL_SECONDS),
            ])->save();

            try {
                $usage = $this->openUsage->execute($suggestion, Provider::Anthropic, $estimate, $rates, TakeChangeAgent::MODEL);
            } catch (InsufficientCreditsException) {
                // Rolls back the suggestion too: no suggestion, no usage, no hold.
                throw new SuggestionDeniedException(DenialReason::InsufficientCredits);
            }

            return [$suggestion, $usage];
        });
    }

    /**
     * Lazy expiry (spec 8 "Expiry"): before T1, void every expired pending
     * suggestion of this customer (each in its own transaction, lock order
     * suggestion → customer), so no orphaned hold lowers the available credit
     * this request is checked against, and no stale row blocks the base take.
     * Runs before T1 because T1 already holds the customer lock. Spec 5's
     * sweeper does the same for customers who never come back.
     */
    private function expireStalePending(Customer $customer): void
    {
        $stale = Suggestion::query()
            ->where('customer_id', $customer->id)
            ->where('status', SuggestionStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->get();

        // Not Collection::each: a row another process already expired returns false, which would stop it.
        foreach ($stale as $suggestion) {
            $this->expireSuggestion->execute($suggestion);
        }
    }

    /** T2: lock order suggestion → customer (the money actions lock the customer). */
    private function close(Suggestion $suggestion, TakeChangeResultDTO $result, RatesSnapshotDTO $rates): Suggestion
    {
        return DB::transaction(function () use ($suggestion, $result, $rates): Suggestion {
            $suggestion = Suggestion::query()->lockForUpdate()->findOrFail($suggestion->id);

            if ($suggestion->status !== SuggestionStatus::Pending) {
                // Expired by the sweeper while the call ran: already failed and voided.
                Log::warning('suggestions.closed_before_result', ['suggestion_id' => $suggestion->id, 'suggestion_status' => $suggestion->status->value]);

                return $suggestion;
            }

            $usage = $suggestion->usageRecord()->firstOrFail();

            if ($result->proposal !== null && $result->units !== null) {
                $suggestion->forceFill([
                    'status' => SuggestionStatus::Proposed,
                    'proposal' => $result->proposal->toArray(),
                    'rationale' => $result->proposal->rationale,
                ])->save();

                $this->settleUsage->execute($usage, $result->units, $rates, CarbonImmutable::now(), $result->externalId);

                return $suggestion;
            }

            $suggestion->forceFill([
                'status' => SuggestionStatus::Failed,
                'failure_reason' => $result->failure,
            ])->save();

            $this->voidUsage->execute($usage);

            return $suggestion;
        });
    }
}
