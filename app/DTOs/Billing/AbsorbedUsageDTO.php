<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use Carbon\CarbonImmutable;

/**
 * Provider work that consumed units but is not charged: an AI call that came
 * back unusable (refusal, `max_tokens`, output that fails re-validation) or
 * after its suggestion expired. The customer pays nothing (M-13); the
 * provider still bills these units, so VoidUsageAction records their cost as
 * `absorbed_cost_nanos` for the daily AI budget (spec 8 Guardrails).
 */
final readonly class AbsorbedUsageDTO
{
    public function __construct(
        public UsageUnitsDTO $units,
        public RatesSnapshotDTO $rates,
        public CarbonImmutable $completedAt,
        public ?string $externalId = null,
    ) {}

    /**
     * @param  array{units: UsageUnitsDTO, rates: RatesSnapshotDTO, completed_at: CarbonImmutable, external_id?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['units'], $data['rates'], $data['completed_at'], $data['external_id'] ?? null);
    }
}
