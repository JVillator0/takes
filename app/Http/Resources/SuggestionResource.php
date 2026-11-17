<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Suggestions\SuggestionDetailsDTO;
use App\DTOs\Suggestions\TakeChangeInputDTO;
use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Enums\Billing\UsageStatus;
use App\Enums\Suggestions\SuggestionFailureReason;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Models\Take;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Suggestions\ProposalReferences;
use App\Support\Suggestions\WordDiff;
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
            'diff' => $proposal === null ? null : $this->diff($proposal, $details->baseTake),
            'newTake' => $this->newTake(),
            // The proposal adds the base image but it has no usable URL: apply is refused.
            'baseImageUnavailable' => $proposal !== null && ProposalReferences::baseImageUnavailable($proposal, $details->baseTake),
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
     * @return array<string, mixed>
     */
    private function diff(TakeChangeProposalDTO $proposal, Take $base): array
    {
        $baseReferences = count($base->reference_images);
        $keptReferences = $proposal->keepReferences ? $baseReferences : 0;
        // Same resolver as apply and the estimate (spec 4 remix source order).
        $imageUnavailable = ProposalReferences::baseImageUnavailable($proposal, $base);
        $afterReferences = $proposal->addBaseImageAsReference && ! $imageUnavailable
            ? __('suggestions.diff.references_with_image', ['count' => $keptReferences])
            : (string) $keptReferences;

        return [
            'prompt' => [
                'changed' => $proposal->prompt !== $base->prompt,
                'segments' => WordDiff::segments($base->prompt, $proposal->prompt),
            ],
            'rows' => [
                self::row('size', self::sizeLabel($base->size), self::sizeLabel($proposal->size), $base->size !== $proposal->size),
                self::row('quality', self::qualityLabel($base->quality), self::qualityLabel($proposal->quality), $base->quality !== $proposal->quality),
                self::row(
                    'references',
                    (string) $baseReferences,
                    $afterReferences,
                    $proposal->referenceCount($baseReferences) !== $baseReferences || $proposal->addBaseImageAsReference,
                    $imageUnavailable ? self::copy('takes.image_unavailable') : null,
                ),
            ],
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

    /**
     * @return array{key: string, label: string, before: string, after: string, changed: bool, note: string|null}
     */
    private static function row(string $key, string $before, string $after, bool $changed, ?string $note = null): array
    {
        return [
            'key' => $key,
            'label' => __("suggestions.diff.{$key}"),
            'before' => $before,
            'after' => $after,
            'changed' => $changed,
            'note' => $note,
        ];
    }

    private static function sizeLabel(TakeSize $size): string
    {
        return __("takes.sizes.{$size->value}").' '.str_replace('x', '×', $size->value);
    }

    private static function qualityLabel(TakeQuality $quality): string
    {
        return __("takes.quality.{$quality->value}");
    }

    /** A lang line as a string (a group key would return an array). */
    private static function copy(string $key): string
    {
        $line = __($key);

        return is_string($line) ? $line : $key;
    }
}
