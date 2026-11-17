<?php

declare(strict_types=1);

use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;

describe('parsing', function (): void {
    it('parses USD decimal strings into exact nanodollars', function (string $input, int $expected): void {
        expect(Nanodollars::fromDecimalString($input)->toInt())->toBe($expected);
    })->with([
        'docs worked example' => ['0.373875000', 373_875_000],
        'short fraction' => ['0.10', 100_000_000],
        'one nanodollar' => ['0.000000001', 1],
        'whole dollars' => ['5', 5_000_000_000],
        'leading zeros' => ['007.5', 7_500_000_000],
        'zero' => ['0', 0],
        'negative' => ['-0.5', -500_000_000],
        'negative zero' => ['-0.000', 0],
        'largest amount' => ['9223372036.854775807', PHP_INT_MAX],
    ]);

    it('rejects anything that is not a plain decimal with at most 9 decimals', function (string $input): void {
        expect(fn () => Nanodollars::fromDecimalString($input))->toThrow(InvalidArgumentException::class);
    })->with([
        'ten decimals' => ['0.0000000001'],
        'float notation' => ['1e3'],
        'trailing dot' => ['1.'],
        'leading dot' => ['.5'],
        'plus sign' => ['+1'],
        'whitespace' => [' 1'],
        'comma' => ['1,5'],
        'empty' => [''],
        'currency sign' => ['$1'],
    ]);

    it('rejects amounts that do not fit in 64 bits', function (): void {
        expect(fn () => Nanodollars::fromDecimalString('9223372036.854775808'))->toThrow(OverflowException::class);
        expect(fn () => Nanodollars::fromInt('99999999999999999999'))->toThrow(OverflowException::class);
    });

    it('parses rates per 1M tokens and rejects negative rates', function (): void {
        expect(Nanodollars::perMillionFromDecimalString('12.50')->toInt())->toBe(12_500_000_000)
            ->and(Nanodollars::perMillionFromDecimalString('0.10')->toInt())->toBe(100_000_000);

        expect(fn () => Nanodollars::perMillionFromDecimalString('-1'))->toThrow(InvalidArgumentException::class);
    });

    it('wraps integers and integer strings from SQL sums', function (): void {
        expect(Nanodollars::fromInt(42)->toInt())->toBe(42)
            ->and(Nanodollars::fromInt('373875000')->toInt())->toBe(373_875_000)
            ->and(Nanodollars::fromInt('-15')->toInt())->toBe(-15)
            ->and(Nanodollars::fromInt('0')->toInt())->toBe(0);

        expect(fn () => Nanodollars::fromInt('12.0'))->toThrow(InvalidArgumentException::class);
        expect(fn () => Nanodollars::fromInt(PHP_INT_MIN))->toThrow(OverflowException::class);
    });
});

describe('arithmetic', function (): void {
    it('adds, subtracts and compares exactly', function (): void {
        $balance = Nanodollars::fromDecimalString('1.00');
        $held = Nanodollars::fromDecimalString('1.25');
        $available = $balance->minus($held);

        expect($available->toInt())->toBe(-250_000_000)
            ->and($available->isNegative())->toBeTrue()
            ->and($available->plus($held)->equals($balance))->toBeTrue()
            ->and($balance->greaterThanOrEqual($balance))->toBeTrue()
            ->and($balance->greaterThanOrEqual($held))->toBeFalse()
            ->and($balance->lessThan($held))->toBeTrue()
            ->and(Nanodollars::zero()->isZero())->toBeTrue();
    });

    it('throws instead of overflowing', function (): void {
        $max = Nanodollars::fromInt(PHP_INT_MAX);

        expect(fn () => $max->plus(Nanodollars::fromInt(1)))->toThrow(OverflowException::class);
        expect(fn () => Nanodollars::fromInt(-PHP_INT_MAX)->minus(Nanodollars::fromInt(1)))->toThrow(OverflowException::class);
    });

    it('applies the 25% markup with ceil in integer math', function (int $cost, int $charge): void {
        expect(Nanodollars::fromInt($cost)->withMarkup()->toInt())->toBe($charge);
    })->with([
        'docs worked example' => [373_875_000, 467_343_750],
        'zero cost' => [0, 0],
        'exact multiple of 4' => [4, 5],
        'ceil guard on an odd cost' => [1, 2],
        'ceil guard just below exact' => [3, 4],
    ]);

    it('accepts other markups and rejects negative ones', function (): void {
        expect(Nanodollars::fromInt(1000)->withMarkup(0)->toInt())->toBe(1000)
            ->and(Nanodollars::fromInt(1000)->withMarkup(10_000)->toInt())->toBe(2000);

        expect(fn () => Nanodollars::fromInt(1000)->withMarkup(-1))->toThrow(InvalidArgumentException::class);
        expect(fn () => Nanodollars::fromInt(-1000)->withMarkup())->toThrow(InvalidArgumentException::class);
    });
});

describe('formatting', function (): void {
    it('renders all nine decimals as a decimal string', function (int $nanos, string $expected): void {
        expect(Nanodollars::fromInt($nanos)->toDecimalString())->toBe($expected);
    })->with([
        [74_037_500, '0.074037500'],
        [5_000_000_000, '5.000000000'],
        [-500_000_000, '-0.500000000'],
        [0, '0.000000000'],
    ]);

    it('round-trips through the decimal string', function (string $decimal): void {
        expect(Nanodollars::fromDecimalString($decimal)->toDecimalString())->toBe($decimal);
    })->with(['0.373875000', '12.500000000', '-3.000000001']);

    it('formats rounded amounts per mode at the half boundary', function (string $amount, RoundingMode $mode, string $expected): void {
        expect(Nanodollars::fromDecimalString($amount)->format(4, $mode))->toBe($expected);
    })->with([
        'x.49999 half-up stays' => ['0.000049999', RoundingMode::HalfUp, '$0.0000'],
        'x.5 half-up goes up' => ['0.00005', RoundingMode::HalfUp, '$0.0001'],
        'x.49999 floor' => ['0.000049999', RoundingMode::Floor, '$0.0000'],
        'x.49999 ceil' => ['0.000049999', RoundingMode::Ceil, '$0.0001'],
        'exact floor' => ['0.0001', RoundingMode::Floor, '$0.0001'],
        'exact ceil' => ['0.0001', RoundingMode::Ceil, '$0.0001'],
        'exact half-up' => ['0.0001', RoundingMode::HalfUp, '$0.0001'],
        'docs example half-up' => ['0.373875', RoundingMode::HalfUp, '$0.3739'],
        'docs example floor' => ['0.373875', RoundingMode::Floor, '$0.3738'],
        'docs example ceil' => ['0.373875', RoundingMode::Ceil, '$0.3739'],
        'carry into dollars' => ['0.99995', RoundingMode::HalfUp, '$1.0000'],
        'negative half-up' => ['-0.00005', RoundingMode::HalfUp, '-$0.0001'],
        'negative floor goes down' => ['-0.000049999', RoundingMode::Floor, '-$0.0001'],
        'negative ceil goes up' => ['-0.000049999', RoundingMode::Ceil, '$0.0000'],
        'negative rounding to zero has no sign' => ['-0.000049999', RoundingMode::HalfUp, '$0.0000'],
    ]);

    it('formats with other precisions and defaults to 4 decimals half-up', function (): void {
        $amount = Nanodollars::fromDecimalString('5.125');

        expect($amount->format())->toBe('$5.1250')
            ->and($amount->format(2))->toBe('$5.13')
            ->and($amount->format(0))->toBe('$5')
            ->and($amount->format(9))->toBe('$5.125000000');

        expect(fn () => $amount->format(10))->toThrow(InvalidArgumentException::class);
    });

    it('formats exact amounts trimmed but with at least 2 decimals', function (string $amount, string $expected): void {
        expect(Nanodollars::fromDecimalString($amount)->formatExact())->toBe($expected);
    })->with([
        ['5', '$5.00'],
        ['0.0735', '$0.0735'],
        ['0.373875', '$0.373875'],
        ['0.000000001', '$0.000000001'],
        ['1.5', '$1.50'],
        ['0', '$0.00'],
        ['-0.25', '-$0.25'],
    ]);
});

it('contains no float or built-in rounding helpers', function (): void {
    $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 3).'/app/Support/Money/Nanodollars.php'));
    $code = array_map(
        fn (array|string $token): string => is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
            : $token,
        $tokens,
    );
    $source = implode('', $code);

    foreach (['float', 'round(', 'floatval', 'number_format(', 'floor(', 'ceil(', 'bcmath', '**'] as $forbidden) {
        expect(str_contains($source, $forbidden))->toBeFalse("Nanodollars must not use [{$forbidden}].");
    }
});
