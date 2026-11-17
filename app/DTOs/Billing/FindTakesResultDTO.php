<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

/**
 * The four groups of the A04 drill-down (D-034, D-058): generations Pixelforge billed that we have no take for,
 * takes we have not settled, settled takes whose cost, tokens or billing day differ (and our settled usage that
 * Pixelforge did not list), and orphan successes (the take was failed free; Pixelforge's cost is absorbed, never
 * a plain mismatch). `truncated` is true when the provider list was longer than the pages read. Cached as a plain
 * array (toArray / fromArray), never as an object.
 *
 * @phpstan-type RowPayload array{generation_id: string, take_id: string|null, their_cost_nanos: int|null, our_cost_nanos: int|null}
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

    /**
     * @param  array{date: string, truncated: bool, theirs_not_ours: list<RowPayload>, ours_not_settled: list<RowPayload>, amount_differs: list<RowPayload>, orphan_success: list<RowPayload>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['date'],
            self::rows($data['theirs_not_ours']),
            self::rows($data['ours_not_settled']),
            self::rows($data['amount_differs']),
            self::rows($data['orphan_success']),
            $data['truncated'],
        );
    }

    /**
     * @return array{date: string, truncated: bool, theirs_not_ours: list<RowPayload>, ours_not_settled: list<RowPayload>, amount_differs: list<RowPayload>, orphan_success: list<RowPayload>}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'truncated' => $this->truncated,
            'theirs_not_ours' => self::payloads($this->theirsNotOurs),
            'ours_not_settled' => self::payloads($this->oursNotSettled),
            'amount_differs' => self::payloads($this->amountDiffers),
            'orphan_success' => self::payloads($this->orphanSuccess),
        ];
    }

    /**
     * @param  list<RowPayload>  $group
     * @return list<ReconciliationGenerationDTO>
     */
    private static function rows(array $group): array
    {
        return array_map(ReconciliationGenerationDTO::fromArray(...), $group);
    }

    /**
     * @param  list<ReconciliationGenerationDTO>  $group
     * @return list<RowPayload>
     */
    private static function payloads(array $group): array
    {
        return array_map(fn (ReconciliationGenerationDTO $row): array => $row->toArray(), $group);
    }
}
