<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * The billing page cards (spec 6, P08): balance, on hold, available
 * (D-024) and this UTC month's spend by feature, bucketed by settlement date.
 */
final readonly class BillingSummaryDTO
{
    public function __construct(
        public Nanodollars $balance,
        public Nanodollars $held,
        public Nanodollars $available,
        public Nanodollars $spentImages,
        public Nanodollars $spentAiSuggestions,
        public CarbonImmutable $monthStart,
    ) {}

    /**
     * @param  array{balance: Nanodollars, held: Nanodollars, spent_images_nanos: int|string, spent_ai_suggestions_nanos: int|string, month_start: CarbonImmutable}  $data
     */
    public static function fromArray(array $data): self
    {
        $balance = $data['balance'];
        $held = $data['held'];

        return new self(
            balance: $balance,
            held: $held,
            available: $balance->minus($held),
            spentImages: Nanodollars::fromInt($data['spent_images_nanos']),
            spentAiSuggestions: Nanodollars::fromInt($data['spent_ai_suggestions_nanos']),
            monthStart: $data['month_start'],
        );
    }

    /** Rounded from nothing: the exact sum of the two exact parts (D-042). */
    public function spentTotal(): Nanodollars
    {
        return $this->spentImages->plus($this->spentAiSuggestions);
    }
}
