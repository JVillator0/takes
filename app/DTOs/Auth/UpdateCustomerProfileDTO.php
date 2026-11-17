<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Http\Requests\Settings\ProfileUpdateRequest;

/**
 * A validated profile change (settings page). The email is already lowercased.
 */
final readonly class UpdateCustomerProfileDTO
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    public static function fromRequest(ProfileUpdateRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
        );
    }
}
