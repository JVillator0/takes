<?php

declare(strict_types=1);

namespace App\DTOs\Suggestions;

use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Suggestions\SuggestionFailureReason;
use InvalidArgumentException;

/**
 * The outcome of one "Describe a change" call, made outside any transaction
 * and applied in T2 (spec 8): a validated proposal with the exact token units
 * from the response's usage block, or a failure reason (voided, free).
 */
final readonly class TakeChangeResultDTO
{
    public function __construct(
        public ?TakeChangeProposalDTO $proposal,
        public ?UsageUnitsDTO $units,
        public ?SuggestionFailureReason $failure,
        public ?string $externalId = null,
    ) {
        if (($proposal === null) === ($failure === null)) {
            throw new InvalidArgumentException('A take change result is either a proposal or a failure.');
        }

        if ($proposal !== null && $units === null) {
            throw new InvalidArgumentException('A proposal needs the call\'s token units to be settled.');
        }
    }

    public static function proposed(TakeChangeProposalDTO $proposal, UsageUnitsDTO $units, ?string $externalId): self
    {
        return new self($proposal, $units, null, $externalId);
    }

    public static function failed(SuggestionFailureReason $reason, ?UsageUnitsDTO $units = null, ?string $externalId = null): self
    {
        return new self(null, $units, $reason, $externalId);
    }

    /**
     * @param  array{proposal: TakeChangeProposalDTO|null, units: UsageUnitsDTO|null, failure: SuggestionFailureReason|null, external_id?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['proposal'], $data['units'], $data['failure'], $data['external_id'] ?? null);
    }
}
