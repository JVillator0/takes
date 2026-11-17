<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

/**
 * A validated self-registration (Fortify register form, D-030).
 */
final readonly class RegisterCustomerDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
        );
    }
}
