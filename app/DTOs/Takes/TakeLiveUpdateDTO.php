<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

use App\Models\Customer;
use App\Models\Take;

/**
 * The `take.updated` broadcast payload (spec 5, W-19): which take changed and
 * the customer's balance pill, money as server-formatted strings (M-20).
 * Prices only, never provider cost, tokens, provider names or margin (D-023,
 * M-23). The card itself is refreshed with a scoped partial reload, so card
 * copy and money lines keep their single source (the take resource, 2-01).
 *
 * @phpstan-type TakeLiveUpdatePayload array{
 *     take: array{id: string, projectId: string, status: string, failureCode: string|null, updatedAt: string|null},
 *     balance: array{available: string, availableExact: string, onHold: string, onHoldExact: string},
 * }
 */
final readonly class TakeLiveUpdateDTO
{
    public function __construct(
        public string $takeId,
        public string $projectId,
        public string $status,
        public ?string $failureCode,
        public ?string $updatedAt,
        public string $available,
        public string $availableExact,
        public string $onHold,
        public string $onHoldExact,
    ) {}

    public static function fromTake(Take $take, Customer $customer): self
    {
        return new self(
            takeId: $take->id,
            projectId: $take->project_id,
            status: $take->status->value,
            failureCode: $take->failure_code?->value,
            updatedAt: $take->updated_at?->toIso8601ZuluString(),
            available: $customer->available()->format(4),
            availableExact: $customer->available()->formatExact(),
            onHold: $customer->held()->format(4),
            onHoldExact: $customer->held()->formatExact(),
        );
    }

    /**
     * @param  TakeLiveUpdatePayload  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            takeId: $data['take']['id'],
            projectId: $data['take']['projectId'],
            status: $data['take']['status'],
            failureCode: $data['take']['failureCode'],
            updatedAt: $data['take']['updatedAt'],
            available: $data['balance']['available'],
            availableExact: $data['balance']['availableExact'],
            onHold: $data['balance']['onHold'],
            onHoldExact: $data['balance']['onHoldExact'],
        );
    }

    /**
     * @return TakeLiveUpdatePayload
     */
    public function toArray(): array
    {
        return [
            'take' => [
                'id' => $this->takeId,
                'projectId' => $this->projectId,
                'status' => $this->status,
                'failureCode' => $this->failureCode,
                'updatedAt' => $this->updatedAt,
            ],
            'balance' => [
                'available' => $this->available,
                'availableExact' => $this->availableExact,
                'onHold' => $this->onHold,
                'onHoldExact' => $this->onHoldExact,
            ],
        ];
    }
}
