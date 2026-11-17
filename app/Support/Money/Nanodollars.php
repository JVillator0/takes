<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;
use OverflowException;

/**
 * An exact amount of money in integer nanodollars (1 USD = 1,000,000,000), D-002.
 *
 * Every amount in the app (provider cost, charge, balance, hold) is one of these.
 * Parsing and formatting are pure integer/string operations: no floats, no
 * built-in rounding helpers, so a value is never off by a single nanodollar.
 * Spec 3-03 §1.
 */
final readonly class Nanodollars
{
    public const int PER_DOLLAR = 1_000_000_000;

    /** README rule: customers pay the provider cost + 25% (spec 3-03 §3). */
    public const int DEFAULT_MARKUP_BPS = 2500;

    private const int SCALE = 9;

    private const int BPS_DENOMINATOR = 10_000;

    /** Integer powers of ten, so no `**` (which may yield a float) is needed. */
    private const array POWERS_OF_TEN = [
        1,
        10,
        100,
        1_000,
        10_000,
        100_000,
        1_000_000,
        10_000_000,
        100_000_000,
        1_000_000_000,
    ];

    private function __construct(public int $nanos)
    {
        // PHP_INT_MIN has no positive counterpart; excluding it keeps abs() an int.
        if ($nanos === PHP_INT_MIN) {
            throw new OverflowException('Nanodollar amount out of range.');
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses a USD decimal string such as "0.373875000" or "-5", at most 9
     * fraction digits (Pixelforge is exact to the nanodollar).
     */
    public static function fromDecimalString(string $value): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,9}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid USD decimal string [{$value}]: expected digits with at most 9 decimals.");
        }

        $fraction = str_pad($matches[3] ?? '', self::SCALE, '0');
        $magnitude = self::parseMagnitude($matches[2].$fraction);

        return new self($matches[1] === '-' ? -$magnitude : $magnitude);
    }

    /**
     * Parses a rate in USD per 1M tokens ("12.50") into nanodollars per 1M
     * tokens (12_500_000_000). Pixelforge rates and config/ai-pricing.php both
     * go through here. Rates are never negative.
     */
    public static function perMillionFromDecimalString(string $ratePerMillion): self
    {
        $rate = self::fromDecimalString($ratePerMillion);

        if ($rate->isNegative()) {
            throw new InvalidArgumentException("A rate cannot be negative [{$ratePerMillion}].");
        }

        return $rate;
    }

    /**
     * Wraps an integer amount of nanodollars, or an integer string as returned
     * by SQL sums (Postgres `sum(bigint)` comes back as a numeric string).
     */
    public static function fromInt(int|string $nanos): self
    {
        if (is_int($nanos)) {
            return new self($nanos);
        }

        if (preg_match('/^(-?)(\d+)$/', $nanos, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid integer nanodollar string [{$nanos}].");
        }

        $magnitude = self::parseMagnitude($matches[2]);

        return new self($matches[1] === '-' ? -$magnitude : $magnitude);
    }

    public function toInt(): int
    {
        return $this->nanos;
    }

    public function plus(self $other): self
    {
        if (($other->nanos > 0 && $this->nanos > PHP_INT_MAX - $other->nanos)
            || ($other->nanos < 0 && $this->nanos < PHP_INT_MIN + 1 - $other->nanos)) {
            throw new OverflowException('Nanodollar addition overflows.');
        }

        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        if (($other->nanos < 0 && $this->nanos > PHP_INT_MAX + $other->nanos)
            || ($other->nanos > 0 && $this->nanos < PHP_INT_MIN + 1 + $other->nanos)) {
            throw new OverflowException('Nanodollar subtraction overflows.');
        }

        return new self($this->nanos - $other->nanos);
    }

    public function isNegative(): bool
    {
        return $this->nanos < 0;
    }

    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->nanos >= $other->nanos;
    }

    public function lessThan(self $other): bool
    {
        return $this->nanos < $other->nanos;
    }

    /**
     * The customer charge for this provider cost (spec 3-03 §3, README rule):
     * charge = ceil(cost × (10_000 + bps) / 10_000), in integer math. With
     * current rates it is exact; if not, it rounds up by at most 1 nanodollar.
     */
    public function withMarkup(int $markupBps = self::DEFAULT_MARKUP_BPS): self
    {
        if ($markupBps < 0) {
            throw new InvalidArgumentException('Markup basis points cannot be negative.');
        }

        if ($this->isNegative()) {
            throw new InvalidArgumentException('A markup applies to a cost, which cannot be negative.');
        }

        $multiplier = self::BPS_DENOMINATOR + $markupBps;

        if ($this->nanos > intdiv(PHP_INT_MAX - (self::BPS_DENOMINATOR - 1), $multiplier)) {
            throw new OverflowException('Nanodollar markup overflows.');
        }

        return new self(intdiv($this->nanos * $multiplier + self::BPS_DENOMINATOR - 1, self::BPS_DENOMINATOR));
    }

    /** All nine decimals, no currency sign: "0.074037500", "-5.000000000". */
    public function toDecimalString(): string
    {
        $magnitude = abs($this->nanos);

        return ($this->isNegative() ? '-' : '')
            .intdiv($magnitude, self::PER_DOLLAR)
            .'.'
            .str_pad((string) ($magnitude % self::PER_DOLLAR), self::SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * Rounded display for lists, cards and the balance pill (D-021):
     * estimate minimum Floor, maximum Ceil, charges HalfUp. "$0.3739".
     */
    public function format(int $decimals = 4, RoundingMode $mode = RoundingMode::HalfUp): string
    {
        if ($decimals < 0 || $decimals > self::SCALE) {
            throw new InvalidArgumentException('Decimals must be between 0 and 9.');
        }

        $negative = $this->isNegative();
        $magnitude = abs($this->nanos);
        $divisor = self::POWERS_OF_TEN[self::SCALE - $decimals];
        $scaled = intdiv($magnitude, $divisor);
        $remainder = $magnitude % $divisor;

        // Floor/Ceil round toward -inf/+inf, so for a negative amount they move
        // the magnitude the opposite way. HalfUp rounds half away from zero.
        $roundMagnitudeUp = match ($mode) {
            RoundingMode::Floor => $negative && $remainder > 0,
            RoundingMode::Ceil => ! $negative && $remainder > 0,
            RoundingMode::HalfUp => $remainder * 2 >= $divisor,
        };

        if ($roundMagnitudeUp) {
            $scaled++;
        }

        $unit = self::POWERS_OF_TEN[$decimals];
        $fraction = $decimals > 0
            ? '.'.str_pad((string) ($scaled % $unit), $decimals, '0', STR_PAD_LEFT)
            : '';

        return ($negative && $scaled > 0 ? '-' : '').'$'.intdiv($scaled, $unit).$fraction;
    }

    /**
     * Exact display for money pages and admin (D-042): trailing zeros trimmed,
     * never fewer than 2 decimals. "$5.00", "$0.0735", "$0.373875".
     */
    public function formatExact(): string
    {
        $magnitude = abs($this->nanos);
        $fraction = rtrim(str_pad((string) ($magnitude % self::PER_DOLLAR), self::SCALE, '0', STR_PAD_LEFT), '0');

        return ($this->isNegative() ? '-' : '')
            .'$'
            .intdiv($magnitude, self::PER_DOLLAR)
            .'.'
            .str_pad($fraction, 2, '0');
    }

    /**
     * Turns a string of digits into a non-negative int, rejecting values that
     * do not fit in a 64-bit integer (a plain cast would silently saturate).
     */
    private static function parseMagnitude(string $digits): int
    {
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return 0;
        }

        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new OverflowException("Nanodollar amount out of range [{$digits}].");
        }

        return (int) $digits;
    }
}
