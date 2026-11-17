<?php

declare(strict_types=1);

use App\Filament\Admin\Support\Money;

it('prints basis points as a percentage with one decimal, rounded half up', function (?int $bps, string $expected): void {
    expect(Money::percentFromBps($bps))->toBe($expected);
})->with([
    'margin invariant' => [2000, '20.0%'],
    'failure rate' => [384, '3.8%'],
    'half up' => [385, '3.9%'],
    'zero' => [0, '0.0%'],
    'negative' => [-125, '-1.3%'],
    'no ratio' => [null, '-'],
]);

it('prints nanodollars exactly or as grouped integers', function (): void {
    expect(Money::exact(74_037_500))->toBe('$0.0740375')
        ->and(Money::exact('10000000000'))->toBe('$10.00')
        ->and(Money::nanos(-74_037_500))->toBe('-74,037,500')
        ->and(Money::exact(null))->toBe('-');
});
