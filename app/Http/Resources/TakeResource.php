<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Takes\TakeImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/**
 * A take as the customer sees it in the grid (P04), the live update and
 * `GET /takes/{take}` (spec 4). Mirrors `TakeCardData` in
 * resources/js/types/takes.ts field by field.
 *
 * Prices only (D-023, M-23): the hold, the amount charged and the 2-01
 * sentences, never provider cost, tokens, rates, provider names, the
 * provider id or the provider's error text. Money arrives formatted (M-20):
 * the card line is rounded to 4 decimals (holds up, charges half-up, D-021)
 * and `moneyExact` is the exact amount for the hover (D-042).
 *
 * The take needs its `usageRecord` loaded and the `number` (and, for a
 * remix, `parent_number`) attributes from ListTakeNumbersQuery.
 *
 * @property Take $resource
 */
final class TakeResource extends JsonResource
{
    /** Failures where trying again cannot help (D-064). */
    private const array NO_RETRY = [FailureCode::ContentPolicy, FailureCode::Internal];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $take = $this->resource;
        $phase = self::phase($take);
        $usage = $take->usageRecord;

        return [
            'id' => $take->id,
            'number' => self::number($take, 'number'),
            'projectId' => $take->project_id,
            'status' => $take->status->value,
            'phase' => $phase,
            'statusLabel' => __("takes.status.{$phase}"),
            'size' => $take->size->value,
            'quality' => $take->quality->value,
            'prompt' => $take->prompt,
            'moneyLine' => self::moneyLine($take, $usage),
            'moneyExact' => self::moneyExact($take, $usage),
            'explanation' => self::explanation($take),
            'imageUrl' => $take->status === TakeStatus::Succeeded ? TakeImageUrl::forDisplay($take) : null,
            'failureCode' => $take->failure_code?->value,
            'failureMessage' => $take->failure_code !== null ? __("takes.failure.{$take->failure_code->value}") : null,
            'parentId' => $take->parent_take_id,
            'parentNumber' => $take->parent_take_id !== null ? self::number($take, 'parent_number') : null,
            'canCancel' => $take->status === TakeStatus::Pending && $take->last_submit_error_kind === SubmitErrorKind::Capacity,
            'canRetry' => $take->status === TakeStatus::Failed && ! in_array($take->failure_code, self::NO_RETRY, true),
            'createdAt' => $take->created_at?->toIso8601ZuluString(),
        ];
    }

    /** The 2-01 display state: `pending` splits into Submitting and Waiting for capacity. */
    public static function phase(Take $take): string
    {
        return match ($take->status) {
            TakeStatus::Pending => $take->last_submit_error_kind === SubmitErrorKind::Capacity ? 'waiting_for_capacity' : 'submitting',
            TakeStatus::Queued => 'queued',
            TakeStatus::Processing => 'processing',
            TakeStatus::Succeeded => 'done',
            TakeStatus::Failed => 'failed',
        };
    }

    /** The 2-01 money line, rounded to 4 decimals (D-021, D-042). */
    public static function moneyLine(Take $take, ?UsageRecord $usage): string
    {
        if ($take->status === TakeStatus::Failed || $usage === null) {
            return __('takes.money.not_charged');
        }

        if ($take->status === TakeStatus::Succeeded) {
            return $usage->status === UsageStatus::Settled
                ? __('takes.money.charged', ['amount' => Nanodollars::fromInt((int) $usage->charge_nanos)->format(4, RoundingMode::HalfUp)])
                : __('takes.money.not_charged');
        }

        $hold = Nanodollars::fromInt($usage->max_charge_nanos)->format(4, RoundingMode::Ceil);

        return $take->last_submit_error_kind === SubmitErrorKind::Capacity && $take->status === TakeStatus::Pending
            ? __('takes.money.on_hold_not_charged_yet', ['amount' => $hold])
            : __('takes.money.on_hold', ['amount' => $hold]);
    }

    /** The exact amount behind the money line, for the hover (D-042); none for "Not charged". */
    public static function moneyExact(Take $take, ?UsageRecord $usage): ?string
    {
        if ($usage === null || $take->status === TakeStatus::Failed) {
            return null;
        }

        if ($take->status === TakeStatus::Succeeded) {
            return $usage->status === UsageStatus::Settled ? Nanodollars::fromInt((int) $usage->charge_nanos)->formatExact() : null;
        }

        return Nanodollars::fromInt($usage->max_charge_nanos)->formatExact();
    }

    /** Why a running take is where it is (2-01 state table); null once it is terminal. */
    public static function explanation(Take $take): ?string
    {
        return match ($take->status) {
            TakeStatus::Pending => match ($take->last_submit_error_kind) {
                SubmitErrorKind::Capacity => $take->last_submit_error === 'concurrency_limit_exceeded'
                    ? self::copy('takes.explanation.waiting_concurrency')
                    : self::copy('takes.explanation.waiting_busy'),
                SubmitErrorKind::Ambiguous => self::copy('takes.explanation.submitting_ambiguous'),
                null => self::copy('takes.explanation.submitting'),
            },
            TakeStatus::Queued => self::copy('takes.explanation.queued'),
            TakeStatus::Processing => self::copy('takes.explanation.processing'),
            default => null,
        };
    }

    /** One 2-01 sentence from lang/en/takes.php (D-057). */
    private static function copy(string $key): string
    {
        return Lang::get($key);
    }

    private static function number(Take $take, string $attribute): ?int
    {
        $value = $take->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : null;
    }
}
