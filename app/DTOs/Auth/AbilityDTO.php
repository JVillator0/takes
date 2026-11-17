<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Enums\Auth\DenialReason;
use Illuminate\Auth\Access\Response;

/**
 * One entry of an Inertia `can` object (spec 3-01): whether the customer may
 * do something and, when not, the `DenialReason` code and its 2-01 copy, e.g.
 * `can.createTake = { allowed: false, reason: 'insufficient_credits', message: '…' }`.
 */
final readonly class AbilityDTO
{
    public function __construct(
        public bool $allowed,
        public ?DenialReason $reason = null,
    ) {}

    /** From `Gate::inspect(...)`; a denial without a known reason code carries none. */
    public static function fromResponse(Response $response): self
    {
        $code = $response->code();

        return new self(
            $response->allowed(),
            $response->allowed() || ! is_string($code) ? null : DenialReason::tryFrom($code),
        );
    }

    /**
     * @return array{allowed: bool, reason: string|null, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason?->value,
            'message' => $this->reason?->message(),
        ];
    }
}
