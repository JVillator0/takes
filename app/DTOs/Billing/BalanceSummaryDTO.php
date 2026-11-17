<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Models\Customer;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;

/**
 * The balance pill (D-024: customers see Available and On hold only): 4
 * decimals on the pill, the exact strings on hover (D-042). Mirrors
 * `BalanceSummary` in resources/js/types/takes.ts. Server-formatted (M-20).
 */
final readonly class BalanceSummaryDTO
{
    public function __construct(
        public Nanodollars $available,
        public Nanodollars $onHold,
    ) {}

    public static function fromCustomer(Customer $customer): self
    {
        return new self($customer->available(), $customer->held());
    }

    /**
     * @param  array{available: int, on_hold: int}  $data  nanodollars
     */
    public static function fromArray(array $data): self
    {
        return new self(Nanodollars::fromInt($data['available']), Nanodollars::fromInt($data['on_hold']));
    }

    /**
     * The 2-01 negative-balance banner ("below zero by $X", 4 decimals), or
     * null while available credits are zero or more (spec 3-03 §6).
     */
    public function negativeBy(): ?string
    {
        return $this->available->isNegative()
            ? Nanodollars::zero()->minus($this->available)->format(4, RoundingMode::HalfUp)
            : null;
    }

    /**
     * @return array{available: string, availableExact: string, onHold: string, onHoldExact: string}
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available->format(4, RoundingMode::HalfUp),
            'availableExact' => $this->available->formatExact(),
            'onHold' => $this->onHold->format(4, RoundingMode::HalfUp),
            'onHoldExact' => $this->onHold->formatExact(),
        ];
    }
}
