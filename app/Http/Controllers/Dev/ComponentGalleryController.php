<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Enums\Suggestions\SuggestionStatus;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dev-only gallery of the take components in every state (`/_ui`).
 *
 * Returns 404 outside the local and testing environments. Fixtures follow the
 * demo dataset (D-037, Ana López at 2026-11-17 12:02 UTC). Every sentence comes
 * from the lang files, so the gallery shows the real copy (D-057), including
 * the denial reason from `lang/en/denials.php` (spec 3-01). Amounts are hard-coded
 * display strings here; in the app the server formats them (D-042).
 */
final class ComponentGalleryController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(app()->environment('local', 'testing'), 404);

        return Inertia::render('dev/ComponentGallery', [
            'balance' => [
                'available' => '$10.3336',
                'availableExact' => '$10.33358125',
                'onHold' => '$0.3140',
                'onHoldExact' => '$0.3139625',
            ],
            'takes' => $this->cards(),
            'extraStates' => $this->extraStates(),
            'counts' => ['all' => 8, 'in_progress' => 3, 'done' => 4, 'failed' => 1],
            'details' => [
                'done' => $this->doneDetail(),
                'failed' => $this->failedDetail(),
                'waiting' => $this->waitingDetail(),
            ],
            'estimates' => [
                'square' => $this->estimate('$0.0581', '$0.0863'),
                'remix' => $this->estimate('$0.0677', '$0.0960'),
                'heavy' => $this->estimate('$0.3577', '$0.4674'),
            ],
            'lowBalance' => [
                'available' => '$0.0100',
                'availableExact' => '$0.01',
                'onHold' => '$0.0000',
                'onHoldExact' => '$0.00',
            ],
            'insufficientMessage' => __('denials.insufficient_credits'),
            'denialMessages' => [
                'daily_take_cap' => __('denials.daily_take_cap'),
                'rate_limited' => __('denials.rate_limited'),
            ],
            'describeChange' => $this->describeChange(),
        ]);
    }

    /**
     * The P07 panel's states (spec 8), shaped like SuggestionResource. The
     * worked example: 1,480 + 212 tokens charged $0.0003175 (D-037 dataset).
     *
     * @return array<string, mixed>
     */
    private function describeChange(): array
    {
        $proposed = [
            'id' => '01JGALLERYSUGGESTION0000001',
            'status' => SuggestionStatus::Proposed->value,
            'instruction' => 'Make it portrait, golden hour, more detail on the waves',
            'rationale' => 'Switched to portrait and high quality for wave detail; kept your composition.',
            'message' => null,
            'noChange' => false,
            'hold' => null,
            'charge' => [
                'exact' => '$0.0003175',
                'disclosure' => __('suggestions.charge_disclosure', ['amount' => '$0.0003175']),
            ],
            'diff' => [
                'prompt' => [
                    'changed' => true,
                    'segments' => [
                        ['type' => 'same', 'text' => 'Lighthouse at '],
                        ['type' => 'removed', 'text' => 'dusk, warm light, '],
                        ['type' => 'added', 'text' => 'golden hour, low sun, '],
                        ['type' => 'same', 'text' => 'cinematic '],
                        ['type' => 'removed', 'text' => 'wide shot, '],
                        ['type' => 'added', 'text' => 'portrait shot, '],
                        ['type' => 'same', 'text' => 'detailed crashing waves'],
                    ],
                ],
                'rows' => [
                    ['key' => 'size', 'label' => __('suggestions.diff.size'), 'before' => __('takes.sizes.1024x1024').' 1024×1024', 'after' => __('takes.sizes.1024x1536').' 1024×1536', 'changed' => true, 'note' => null],
                    ['key' => 'quality', 'label' => __('suggestions.diff.quality'), 'before' => __('takes.quality.medium'), 'after' => __('takes.quality.high'), 'changed' => true, 'note' => null],
                    ['key' => 'references', 'label' => __('suggestions.diff.references'), 'before' => '1', 'after' => __('suggestions.diff.references_with_image', ['count' => 1]), 'changed' => true, 'note' => null],
                ],
            ],
            'newTake' => [
                'range' => __('suggestions.new_take', ['range' => __('takes.estimate.range', ['min' => '$0.3573', 'max' => '$0.4668'])]),
                'generate' => __('takes.composer.submit_with_max', ['amount' => '$0.4668']),
                'canAfford' => true,
            ],
            'resultingTakeId' => null,
            'baseImageUnavailable' => false,
        ];

        return [
            'quote' => ['quote' => __('suggestions.quote', ['amount' => '$0.0012']), 'denial' => null],
            'paused' => ['quote' => __('suggestions.quote', ['amount' => '$0.0012']), 'denial' => ['reason' => 'ai_paused', 'message' => __('denials.ai_paused')]],
            'noCredits' => ['quote' => __('suggestions.quote', ['amount' => '$0.0012']), 'denial' => ['reason' => 'insufficient_credits', 'message' => __('denials.insufficient_credits')]],
            'proposed' => $proposed,
            'unaffordable' => [...$proposed, 'newTake' => [...$proposed['newTake'], 'canAfford' => false]],
            'failed' => [
                ...$proposed,
                'status' => SuggestionStatus::Failed->value,
                'rationale' => null,
                'message' => __('suggestions.failed'),
                'charge' => null,
                'diff' => null,
                'newTake' => null,
            ],
        ];
    }

    /**
     * Ana's eight takes in grid order (newest first, 2-02).
     *
     * @return list<array<string, mixed>>
     */
    private function cards(): array
    {
        return [
            $this->card(13, 'pending', 'waiting_for_capacity', '1536x1024', 'Lighthouse at dawn, mist over the rocks, wide shot', [
                'moneyLine' => __('takes.money.on_hold_not_charged_yet', ['amount' => '$0.1139']),
                'moneyExact' => '$0.11384375',
                'explanation' => __('takes.explanation.waiting_concurrency'),
                'canCancel' => true,
            ]),
            $this->card(12, 'processing', 'processing', '1024x1536', 'Same lighthouse, portrait, stormy sea', [
                'moneyLine' => __('takes.money.on_hold', ['amount' => '$0.1139']),
                'moneyExact' => '$0.113825',
                'explanation' => __('takes.explanation.processing'),
                'parentId' => 10,
            ]),
            $this->card(11, 'queued', 'queued', '1024x1024', 'Night version with stars', [
                'moneyLine' => __('takes.money.on_hold', ['amount' => '$0.0863']),
                'moneyExact' => '$0.08629375',
                'explanation' => __('takes.explanation.queued'),
                'parentId' => 10,
            ]),
            $this->card(10, 'succeeded', 'done', '1024x1024', 'Lighthouse at dusk, warm light, cinematic wide shot, low horizon', [
                'moneyLine' => __('takes.money.charged', ['amount' => '$0.0740']),
                'moneyExact' => '$0.0740375',
                'imageUrl' => $this->image('#f59e0b', '#7c2d12', 1024, 1024),
                'parentId' => 8,
            ]),
            $this->card(9, 'failed', 'failed', '1024x1024', 'Lighthouse [fail]', [
                'quality' => 'low',
                'moneyLine' => __('takes.money.not_charged'),
                'failureCode' => 'generation_failed',
                'failureMessage' => __('takes.failure.generation_failed'),
                'canRetry' => true,
            ]),
            $this->card(8, 'succeeded', 'done', '1536x1024', 'Warmer light, low sun', [
                'moneyLine' => __('takes.money.charged', ['amount' => '$0.1077']),
                'moneyExact' => '$0.107725',
                'imageUrl' => $this->image('#fb923c', '#1e3a8a', 1536, 1024),
                'parentId' => 6,
            ]),
            $this->card(7, 'succeeded', 'done', '1024x1024', 'Black and white version', [
                'moneyLine' => __('takes.money.charged', ['amount' => '$0.0691']),
                'moneyExact' => '$0.0691',
                'imageUrl' => $this->image('#e5e5e5', '#262626', 1024, 1024),
            ]),
            $this->card(6, 'succeeded', 'done', '1024x1536', 'Portrait crop, fog', [
                'moneyLine' => __('takes.money.charged', ['amount' => '$0.1013']),
                'moneyExact' => '$0.10128125',
                'imageUrl' => $this->image('#94a3b8', '#0f172a', 1024, 1536),
            ]),
        ];
    }

    /**
     * States that are not in Ana's grid at 12:02 but must be designed (2-01 tables, P05).
     *
     * @return list<array<string, mixed>>
     */
    private function extraStates(): array
    {
        return [
            $this->card(21, 'pending', 'submitting', '1024x1024', 'A lighthouse on a cliff at dusk', [
                'moneyLine' => __('takes.money.on_hold', ['amount' => '$0.0863']),
                'explanation' => __('takes.explanation.submitting'),
            ]),
            $this->card(22, 'pending', 'submitting', '1024x1024', 'A lighthouse on a cliff at dusk', [
                'moneyLine' => __('takes.money.on_hold', ['amount' => '$0.0863']),
                'explanation' => __('takes.explanation.submitting_ambiguous'),
            ]),
            $this->card(23, 'pending', 'waiting_for_capacity', '1024x1024', 'Lighthouse at dawn', [
                'moneyLine' => __('takes.money.on_hold_not_charged_yet', ['amount' => '$0.0863']),
                'explanation' => __('takes.explanation.waiting_busy'),
                'canCancel' => true,
            ]),
            $this->card(24, 'failed', 'failed', '1024x1024', '[blocked] something', [
                'moneyLine' => __('takes.money.not_charged'),
                'failureCode' => 'content_policy',
                'failureMessage' => __('takes.failure.content_policy'),
            ]),
            $this->card(25, 'failed', 'failed', '1536x1024', 'Lighthouse at dawn, mist over the rocks, wide shot', [
                'moneyLine' => __('takes.money.not_charged'),
                'failureCode' => 'cancelled',
                'failureMessage' => __('takes.failure.cancelled'),
                'canRetry' => true,
            ]),
            // A URL that no longer loads (expired temporary link): the image falls back to "image unavailable" (F-22).
            $this->card(27, 'succeeded', 'done', '1024x1024', 'Harbour at night, expired image link', [
                'moneyLine' => __('takes.money.charged', ['amount' => '$0.0691']),
                'moneyExact' => '$0.0691',
                'imageUrl' => '/_ui/expired-take-image.png',
            ]),
            $this->card(26, 'failed', 'failed', '1024x1024', 'Harbour at night', [
                'moneyLine' => __('takes.money.not_charged'),
                'failureCode' => 'daily_limit',
                'failureMessage' => __('takes.failure.daily_limit'),
                'canRetry' => true,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function doneDetail(): array
    {
        return [
            ...$this->cards()[3],
            'paramsLine' => 'Square 1024×1024 · Medium · 1 reference · AI change from #8',
            'price' => [
                'amount' => '$0.0740375',
                'label' => __('takes.drawer.charged'),
                'tone' => 'charged',
                'estimateLine' => __('takes.drawer.estimate_was', ['range' => __('takes.estimate.range', ['min' => '$0.0677', 'max' => '$0.0960'])]),
                'note' => __('takes.drawer.hold_released', ['amount' => '$0.0960']),
            ],
            'timeline' => [
                $this->event('2026-11-17T12:01:03Z', __('takes.timeline.created', ['amount' => '$0.0960'])),
                $this->event('2026-11-17T12:01:04Z', __('takes.timeline.queued')),
                $this->event('2026-11-17T12:01:09Z', __('takes.timeline.processing')),
                $this->event('2026-11-17T12:01:31Z', __('takes.timeline.done', ['amount' => '$0.0740'])),
            ],
            'parent' => [
                'id' => 8,
                'prompt' => 'Warmer light, low sun',
                'imageUrl' => $this->image('#fb923c', '#1e3a8a', 1536, 1024),
            ],
            'versions' => [
                ['id' => 6, 'label' => '#6 original', 'phase' => 'done', 'imageUrl' => $this->image('#94a3b8', '#0f172a', 1024, 1536), 'current' => false],
                ['id' => 8, 'label' => '#8 warmer', 'phase' => 'done', 'imageUrl' => $this->image('#fb923c', '#1e3a8a', 1536, 1024), 'current' => false],
                ['id' => 10, 'label' => '#10 this take', 'phase' => 'done', 'imageUrl' => $this->image('#f59e0b', '#7c2d12', 1024, 1024), 'current' => true],
                ['id' => 11, 'label' => '#11 queued', 'phase' => 'queued', 'imageUrl' => null, 'current' => false],
                ['id' => 12, 'label' => '#12 processing', 'phase' => 'processing', 'imageUrl' => null, 'current' => false],
            ],
            'downloadUrl' => $this->image('#f59e0b', '#7c2d12', 1024, 1024),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedDetail(): array
    {
        return [
            ...$this->cards()[4],
            'paramsLine' => 'Square 1024×1024 · Low · no references',
            'price' => [
                'amount' => '$0.00',
                'label' => __('takes.drawer.not_charged'),
                'tone' => 'not_charged',
                'estimateLine' => __('takes.drawer.estimate_was', ['range' => __('takes.estimate.range', ['min' => '$0.0157', 'max' => '$0.0226'])]),
                'note' => __('takes.drawer.hold_voided', ['amount' => '$0.0226']),
            ],
            'timeline' => [
                $this->event('2026-11-17T11:40:02Z', __('takes.timeline.created', ['amount' => '$0.0226'])),
                $this->event('2026-11-17T11:40:03Z', __('takes.timeline.queued')),
                $this->event('2026-11-17T11:40:07Z', __('takes.timeline.processing')),
                $this->event('2026-11-17T11:40:19Z', __('takes.timeline.failed')),
            ],
            'parent' => null,
            'versions' => [],
            'downloadUrl' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function waitingDetail(): array
    {
        return [
            ...$this->cards()[0],
            'paramsLine' => 'Landscape 1536×1024 · Medium · no references',
            'price' => [
                'amount' => '$0.11384375',
                'label' => __('takes.drawer.on_hold'),
                'tone' => 'on_hold',
                'estimateLine' => __('takes.drawer.estimate_was', ['range' => __('takes.estimate.range', ['min' => '$0.0855', 'max' => '$0.1139'])]),
                'note' => null,
            ],
            'timeline' => [
                $this->event('2026-11-17T12:02:00Z', __('takes.timeline.created', ['amount' => '$0.1139'])),
            ],
            'parent' => null,
            'versions' => [],
            'downloadUrl' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function card(int $id, string $status, string $phase, string $size, string $prompt, array $overrides = []): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'phase' => $phase,
            'statusLabel' => __('takes.status.'.$phase),
            'size' => $size,
            'quality' => 'medium',
            'prompt' => $prompt,
            'moneyLine' => '',
            'moneyExact' => null,
            'explanation' => null,
            'imageUrl' => null,
            'failureCode' => null,
            'failureMessage' => null,
            'parentId' => null,
            'canCancel' => false,
            'canRetry' => false,
            ...$overrides,
        ];
    }

    /**
     * @return array{at: string, label: mixed}
     */
    private function event(string $at, mixed $label): array
    {
        return ['at' => $at, 'label' => $label];
    }

    /**
     * @return array{min: string, max: string, min_display: string, max_display: string}
     */
    private function estimate(string $min, string $max): array
    {
        return ['min' => $min, 'max' => $max, 'min_display' => $min, 'max_display' => $max];
    }

    /**
     * A gradient SVG standing in for a generated PNG, so the gallery needs no storage.
     */
    private function image(string $from, string $to, int $width, int $height): string
    {
        $sunX = intdiv($width * 72, 100);
        $sunY = intdiv($height * 38, 100);
        $sunRadius = intdiv(min($width, $height) * 6, 100);
        $shore = intdiv($height * 78, 100);
        $wave = intdiv($height * 72, 100);
        $half = intdiv($width, 2);

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}">
              <defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/>
              </linearGradient></defs>
              <rect width="{$width}" height="{$height}" fill="url(#g)"/>
              <circle cx="{$sunX}" cy="{$sunY}" r="{$sunRadius}" fill="#fff7ed" opacity="0.85"/>
              <path d="M0 {$height} L0 {$shore} Q{$half} {$wave} {$width} {$shore} L{$width} {$height} Z" fill="#000" opacity="0.35"/>
            </svg>
            SVG;

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode($svg);
    }
}
