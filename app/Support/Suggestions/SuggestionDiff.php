<?php

declare(strict_types=1);

namespace App\Support\Suggestions;

use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Models\Take;

/**
 * The reviewed diff of a proposal against its base take (spec 8 step 3, P07):
 * the prompt word diff and the Setting / Before / After rows, with the
 * references row resolved by ProposalReferences (spec 4 remix source order),
 * the same resolver apply and the estimate use. Labels come from the lang
 * files (D-057).
 */
final class SuggestionDiff
{
    /**
     * @return array<string, mixed>
     */
    public static function build(TakeChangeProposalDTO $proposal, Take $base): array
    {
        $baseReferences = count($base->reference_images);
        $keptReferences = $proposal->keepReferences ? $baseReferences : 0;
        // Same resolver as apply and the estimate (spec 4 remix source order).
        $baseImageUnavailable = ProposalReferences::baseImageUnavailable($proposal, $base);
        $afterReferences = $proposal->addBaseImageAsReference && ! $baseImageUnavailable
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
                    // The base image or a kept take-image reference no longer resolves (#133).
                    ProposalReferences::referenceUnavailable($proposal, $base) ? self::copy('takes.image_unavailable') : null,
                ),
            ],
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
