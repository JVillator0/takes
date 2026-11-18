<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Suggestions\SuggestionDetailsDTO;
use App\DTOs\Suggestions\TakeChangeInputDTO;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionFailureReason;
use App\Enums\Suggestions\SuggestionStatus;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Suggestions\SuggestionDiff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A suggestion as the P07 panel shows it. Prices only, formatted on the
 * server (M-20): the hold while pending ("Up to $x on hold", 4 decimals
 * rounded up), the exact suggestion charge once settled (D-042: the drawer is
 * a money page), and the new take's range. Never tokens, provider cost,
 * provider or model names, or the margin (D-023, M-23).
 *
 * @property SuggestionDetailsDTO $resource
 */
final class SuggestionResource extends JsonResource
{
    /** No `data` envelope: the panel reads the suggestion directly. */
    public static $wrap = null;

    public function __construct(SuggestionDetailsDTO $details)
    {
        parent::__construct($details);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $details = $this->resource;
        $suggestion = $details->suggestion;
        $proposal = $details->proposal();
        $noChange = $proposal !== null && $proposal->changesNothing(TakeChangeInputDTO::forTake($details->baseTake, $suggestion->instruction));

        return [
            'id' => $suggestion->id,
            'status' => $suggestion->status->value,
            'instruction' => $suggestion->instruction,
            'rationale' => $proposal?->rationale,
            'message' => $this->message($suggestion->status, $suggestion->failure_reason, $noChange),
            'noChange' => $noChange,
            'hold' => $this->hold(),
            'charge' => $this->charge(),
            'diff' => $proposal === null ? null : SuggestionDiff::build($proposal, $details->baseTake, $details->referenceUnavailable),
            'newTake' => $this->newTake(),
            // A reference the new take needs (the base image, or a kept take
            // image, #133) has no usable URL: apply is refused.
            'baseImageUnavailable' => $proposal !== null && $details->referenceUnavailable,
            'resultingTakeId' => $suggestion->resulting_take_id,
        ];
    }

    private function message(SuggestionStatus $status, ?SuggestionFailureReason $reason, bool $noChange): ?string
    {
        return match (true) {
            $status === SuggestionStatus::Failed => self::copy(($reason ?? SuggestionFailureReason::InvalidOutput)->customerCopyKey()),
            $noChange => self::copy('suggestions.no_change'),
            default => null,
        };
    }

    private function hold(): ?string
    {
        $usage = $this->resource->usage;

        if ($usage === null || $usage->status !== UsageStatus::Open) {
            return null;
        }

        return __('takes.money.on_hold', [
            'amount' => Nanodollars::fromInt($usage->max_charge_nanos)->format(4, RoundingMode::Ceil),
        ]);
    }

    /**
     * @return array{exact: string, disclosure: string}|null
     */
    private function charge(): ?array
    {
        $usage = $this->resource->usage;

        if ($usage === null || $usage->status !== UsageStatus::Settled || $usage->charge_nanos === null) {
            return null;
        }

        $exact = Nanodollars::fromInt($usage->charge_nanos)->formatExact();

        return [
            'exact' => $exact,
            'disclosure' => __('suggestions.charge_disclosure', ['amount' => $exact]),
        ];
    }

    /**
     * @return array{range: string, generate: string, canAfford: bool}|null
     */
    private function newTake(): ?array
    {
        $estimate = $this->resource->newTakeEstimate;

        if ($estimate === null) {
            return null;
        }

        $max = $estimate->maxCharge->format(4, RoundingMode::Ceil);

        return [
            'range' => __('suggestions.new_take', ['range' => __('takes.estimate.range', [
                'min' => $estimate->minCharge->format(4, RoundingMode::Floor),
                'max' => $max,
            ])]),
            'generate' => __('takes.composer.submit_with_max', ['amount' => $max]),
            'canAfford' => $this->resource->available->greaterThanOrEqual($estimate->maxCharge),
        ];
    }

    /** A lang line as a string (a group key would return an array). */
    private static function copy(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
