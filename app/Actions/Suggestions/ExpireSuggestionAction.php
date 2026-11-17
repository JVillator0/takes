<?php

declare(strict_types=1);

namespace App\Actions\Suggestions;

use App\Actions\Billing\VoidUsageAction;
use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Suggestion;
use Illuminate\Support\Facades\DB;

/**
 * Fails a suggestion left `pending` (the AI call never reported back, e.g.
 * the process died between T1 and T2): status `failed`, reason `timeout`,
 * usage voided so the hold is released (spec 8 "Expiry", M-13). Run by the
 * sweeper; a suggestion that moved on in the meantime is left alone.
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
            // Lock order: the suggestion first; VoidUsageAction then locks only the customer (M-10).
            $locked = Suggestion::query()->whereKey($suggestion->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== SuggestionStatus::Pending) {
                return false;
            }

            $locked->forceFill([
                'status' => SuggestionStatus::Failed,
                'failure_reason' => self::FAILURE_REASON,
            ])->save();

            $usage = $locked->usageRecord()->first();

            if ($usage !== null) {
                $this->voidUsage->execute($usage);
            }

            return true;
        });
    }
}
