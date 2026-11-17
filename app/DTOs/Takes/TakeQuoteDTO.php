<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Billing\RatesSnapshotDTO;

/**
 * A take's price range and the rates it was computed with. The hold is the
 * estimate's max charge; the rates are stored on the usage when it opens and
 * overwritten at settle with the generation's own rates (D-055).
 */
final readonly class TakeQuoteDTO
{
    public function __construct(
        public EstimateDTO $estimate,
        public RatesSnapshotDTO $rates,
    ) {}

    /**
     * @param  array{estimate: EstimateDTO, rates: RatesSnapshotDTO}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['estimate'], $data['rates']);
    }
}
