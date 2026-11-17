<?php

declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Actions\Billing\VoidUsageAction;
use App\Enums\Suggestions\SuggestionFailureReason;
use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Suggestion;
use Illuminate\Support\Facades\DB;

/**
 * Fails a suggestion left `pending` past its `expires_at` (the AI call never
 * reported back: the process died between T1 and T2, or T2 threw): status
 * `failed`, reason `timeout`, usage voided so the hold is released (spec 8
 * "Expiry", M-13). Its own transaction, lock order suggestion → customer
 * (VoidUsageAction locks the customer, M-10).
 *
 * Called lazily by CreateSuggestionAction for the base take it is about to
 * use, and by spec 5's sweeper for every stale pending suggestion. A
 * suggestion that moved on, or has not expired yet, is left alone.
 */
final readonly class ExpireSuggestionAction
{
    public const string FAILURE_REASON = 'timeout';

    public function __construct(private VoidUsageAction $voidUsage) {}

    /**
     * @return bool whether this call expired it
     */
    public function execute(Suggestion $suggestion): bool
    {
        return DB::transaction(function () use ($suggestion): bool {
            $locked = Suggestion::query()->whereKey($suggestion->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== SuggestionStatus::Pending || $locked->expires_at->isFuture()) {
                return false;
            }

            $locked->forceFill([
                'status' => SuggestionStatus::Failed,
                'failure_reason' => SuggestionFailureReason::Timeout,
            ])->save();

            $usage = $locked->usageRecord()->first();

            if ($usage !== null) {
                $this->voidUsage->execute($usage);
            }

            return true;
        });
    }
}
