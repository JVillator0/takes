<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Takes\TakeDetailDTO;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Takes\TakeImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The take drawer (P06, `?take=` partial reload) and `GET /takes/{take}`:
 * the card fields plus the parameters, the exact price (D-042: the
 * estimate range and the amount charged are exact, trailing zeros trimmed,
 * at least 2 decimals), the timeline, the parent and the version strip.
 * Mirrors `TakeDetailData` in resources/js/types/takes.ts.
 *
 * Prices only (D-023): no provider cost, tokens, rates or provider names.
 *
 * @property TakeDetailDTO $resource
 */
final class TakeDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = $this->resource;
        $take = $detail->take;
        $usage = $take->usageRecord;

        return [
            ...(new TakeResource($take))->toArray($request),
            'paramsLine' => $this->paramsLine($take),
            // Re-issued for display; the parameters line counts the stored ones.
            'referenceImages' => $detail->referenceImages,
            // Remix and Try again pre-fill referenceImages: say how many were left out (2-01).
            'referencesNotice' => $this->referencesNotice(count($take->reference_images) - count($detail->referenceImages)),
            'price' => $this->price($take, $usage),
            'timeline' => $this->timeline($take, $usage),
            'parent' => $detail->parent === null ? null : [
                'id' => $detail->parent->id,
                'number' => $this->number($detail->parent),
                'prompt' => $detail->parent->prompt,
                'imageUrl' => $detail->parent->status === TakeStatus::Succeeded ? TakeImageUrl::forDisplay($detail->parent) : null,
            ],
            'versions' => array_map(fn (Take $version): array => [
                'id' => $version->id,
                'number' => $this->number($version),
                'label' => $version->id === $take->id
                    ? __('takes.drawer.version_current', ['id' => $this->number($version)])
                    : __('takes.drawer.version', ['id' => $this->number($version)]),
                'imageUrl' => $version->status === TakeStatus::Succeeded ? TakeImageUrl::forDisplay($version) : null,
                'current' => $version->id === $take->id,
            ], $detail->versions),
            'downloadUrl' => $this->downloadUrl($take),
            // Remix adds this take's image as a reference: persisted copy, then
            // the provider URL while valid; null disables it (spec 4 "Remix").
            'remixReferenceUrl' => $take->status === TakeStatus::Succeeded ? TakeImageUrl::forReference($take) : null,
        ];
    }

    /** "Square 1024×1024 · Medium · 1 reference · Remix of #8" (P06). */
    private function paramsLine(Take $take): string
    {
        $parts = [
            __("takes.sizes.{$take->size->value}").' '.str_replace('x', '×', $take->size->value),
            __("takes.quality.{$take->quality->value}"),
            trans_choice('takes.drawer.references', count($take->reference_images), ['count' => count($take->reference_images)]),
        ];

        $parentNumber = $take->getAttribute('parent_number');

        if ($take->parent_take_id !== null && is_numeric($parentNumber)) {
            $parts[] = $take->source === TakeSource::AiChange
                ? __('takes.drawer.ai_change_from', ['id' => $parentNumber])
                : __('takes.card.remix_of', ['id' => $parentNumber]);
        }

        return implode(' · ', $parts);
    }

    private function referencesNotice(int $leftOut): ?string
    {
        return $leftOut > 0 ? trans_choice('takes.composer.references_left_out', $leftOut, ['count' => $leftOut]) : null;
    }

    /**
     * @return array{amount: string, label: string, tone: string, estimateLine: string, note: string|null}|null
     */
    private function price(Take $take, ?UsageRecord $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        $max = Nanodollars::fromInt($usage->max_charge_nanos)->formatExact();
        $estimateLine = __('takes.drawer.estimate_was', ['range' => __('takes.estimate.range', [
            'min' => Nanodollars::fromInt($usage->min_charge_nanos)->formatExact(),
            'max' => $max,
        ])]);

        if ($take->status === TakeStatus::Succeeded && $usage->status === UsageStatus::Settled) {
            return [
                'amount' => Nanodollars::fromInt((int) $usage->charge_nanos)->formatExact(),
                'label' => __('takes.drawer.charged'),
                'tone' => 'charged',
                'estimateLine' => $estimateLine,
                'note' => __('takes.drawer.hold_released', ['amount' => $max]),
            ];
        }

        if ($take->status->isTerminal()) {
            return [
                'amount' => Nanodollars::zero()->formatExact(),
                'label' => __('takes.drawer.not_charged'),
                'tone' => 'not_charged',
                'estimateLine' => $estimateLine,
                'note' => __('takes.drawer.hold_voided', ['amount' => $max]),
            ];
        }

        return [
            'amount' => $max,
            'label' => __('takes.drawer.on_hold'),
            'tone' => 'on_hold',
            'estimateLine' => $estimateLine,
            'note' => null,
        ];
    }

    /**
     * What happened, when (P06). Built from the take's own timestamps.
     *
     * @return list<array{at: string, label: string}>
     */
    private function timeline(Take $take, ?UsageRecord $usage): array
    {
        $entries = [];
        $hold = $usage !== null ? Nanodollars::fromInt($usage->max_charge_nanos)->format(4, RoundingMode::Ceil) : null;

        if ($take->created_at !== null && $hold !== null) {
            $entries[] = ['at' => $take->created_at, 'label' => __('takes.timeline.created', ['amount' => $hold])];
        }

        if ($take->pixelforge_id !== null && $take->submitted_at !== null) {
            $entries[] = ['at' => $take->submitted_at, 'label' => __('takes.timeline.queued')];
        }

        if ($take->started_at !== null) {
            $entries[] = ['at' => $take->started_at, 'label' => __('takes.timeline.processing')];
        }

        if ($take->completed_at !== null && $take->status === TakeStatus::Succeeded && $usage?->status === UsageStatus::Settled) {
            $entries[] = ['at' => $take->completed_at, 'label' => __('takes.timeline.done', [
                'amount' => Nanodollars::fromInt((int) $usage->charge_nanos)->format(4, RoundingMode::HalfUp),
            ])];
        } elseif ($take->completed_at !== null && $take->status === TakeStatus::Failed) {
            $entries[] = ['at' => $take->completed_at, 'label' => __('takes.timeline.failed')];
        }

        usort($entries, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_map(fn (array $entry): array => [
            'at' => $entry['at']->toIso8601ZuluString(),
            'label' => $entry['label'],
        ], $entries);
    }

    private function downloadUrl(Take $take): ?string
    {
        if ($take->status !== TakeStatus::Succeeded) {
            return null;
        }

        return $take->image_path !== null
            ? route('takes.image', ['take' => $take, 'download' => 1])
            : TakeImageUrl::forDisplay($take);
    }

    private function number(Take $take): ?int
    {
        $value = $take->getAttribute('number');

        return is_numeric($value) ? (int) $value : null;
    }
}
