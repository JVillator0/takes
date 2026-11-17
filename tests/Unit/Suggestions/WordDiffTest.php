<?php

declare(strict_types=1);

use App\Support\Suggestions\WordDiff;

it('marks removed and added words and keeps the rest', function (): void {
    expect(WordDiff::segments(
        'Lighthouse at dusk, warm light, cinematic wide shot',
        'Lighthouse at golden hour, cinematic portrait shot',
    ))->toBe([
        ['type' => 'same', 'text' => 'Lighthouse at '],
        ['type' => 'removed', 'text' => 'dusk, warm light, '],
        ['type' => 'added', 'text' => 'golden hour, '],
        ['type' => 'same', 'text' => 'cinematic '],
        ['type' => 'removed', 'text' => 'wide '],
        ['type' => 'added', 'text' => 'portrait '],
        ['type' => 'same', 'text' => 'shot'],
    ]);
});

it('returns one same segment when nothing changed', function (): void {
    expect(WordDiff::segments('a calm sea', 'a calm sea'))->toBe([['type' => 'same', 'text' => 'a calm sea']]);
});

it('handles empty sides', function (): void {
    expect(WordDiff::segments('', 'new words'))->toBe([['type' => 'added', 'text' => 'new words']])
        ->and(WordDiff::segments('old words', ''))->toBe([['type' => 'removed', 'text' => 'old words']]);
});

it('keeps multibyte text intact', function (): void {
    expect(WordDiff::segments('café au lait 🌊', 'café noir 🌊'))->toBe([
        ['type' => 'same', 'text' => 'café '],
        ['type' => 'removed', 'text' => 'au lait '],
        ['type' => 'added', 'text' => 'noir '],
        ['type' => 'same', 'text' => '🌊'],
    ]);
});
