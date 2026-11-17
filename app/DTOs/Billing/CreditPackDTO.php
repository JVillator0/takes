<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;

/**
 * A credit pack offered on the billing page (spec 6, D-051). `id` is the
 * `package` value the purchase request sends back; it is an identifier,
 * never shown or computed as money. Simulated purchases carry no tax, so
 * `total` = `amount` today, but the page reads the total, never a sum.
 */
final readonly class CreditPackDTO
{
    public function __construct(
        public string $id,
        public Nanodollars $amount,
        public Nanodollars $tax,
        public Nanodollars $total,
    ) {}

    /**
     * @param  array{id: string, amount: Nanodollars, tax: Nanodollars}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['amount'], $data['tax'], $data['amount']->plus($data['tax']));
    }
}
