<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

/**
 * The four groups of the A04 drill-down (D-034, D-058): generations Pixelforge billed that we have no take for,
 * takes we have not settled, settled takes whose cost or billing day differs, and orphan successes (the take was
 * failed free; Pixelforge's cost is absorbed, never a plain mismatch). `truncated` is true when the provider list
 * was longer than the pages read.
 */
final readonly class FindTakesResultDTO
{
    /**
     * @param  list<ReconciliationGenerationDTO>  $theirsNotOurs
     * @param  list<ReconciliationGenerationDTO>  $oursNotSettled
     * @param  list<ReconciliationGenerationDTO>  $amountDiffers
     * @param  list<ReconciliationGenerationDTO>  $orphanSuccess
     */
    public function __construct(
        public string $date,
        public array $theirsNotOurs,
        public array $oursNotSettled,
        public array $amountDiffers,
        public array $orphanSuccess,
        public bool $truncated,
    ) {}
}
