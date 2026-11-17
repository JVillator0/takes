<?php

declare(strict_types=1);

use App\Filament\Admin\Support\Money;

it('prints basis points as a percentage with one decimal, rounded half away from zero', function (?int $bps, string $expected): void {
    expect(Money::percentFromBps($bps))->toBe($expected);
})->with([
    'margin invariant' => [2000, '20.0%'],
    'failure rate' => [384, '3.8%'],
    'half up' => [385, '3.9%'],
    'zero' => [0, '0.0%'],
    'negative' => [-125, '-1.3%'],
    'tiny negative rounds to plain zero' => [-4, '0.0%'],
    'no ratio' => [null, '-'],
]);

it('prints nanodollars exactly or as grouped integers', function (): void {
    expect(Money::exact(74_037_500))->toBe('$0.0740375')
        ->and(Money::exact('10000000000'))->toBe('$10.00')
        ->and(Money::nanos(-74_037_500))->toBe('-74,037,500')
        ->and(Money::exact(null))->toBe('-');
});

it('groups integer digits without a float conversion, above 2^53 too', function (): void {
    expect(Money::grouped(9_007_199_254_740_993))->toBe('9,007,199,254,740,993')
        ->and(Money::grouped(-74_037_500))->toBe('-74,037,500')
        ->and(Money::grouped(999))->toBe('999')
        ->and(Money::nanos('9007199254740993'))->toBe('9,007,199,254,740,993');
});
