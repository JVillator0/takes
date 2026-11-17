<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

/**
 * One seeded login on the P02 demo panel. The password is public on purpose
 * (README, D-045); the panel only exists when DEMO_LOGIN_ENABLED=true.
 */
final readonly class DemoLoginDTO
{
    public function __construct(
        public string $key,
        public string $label,
        public string $email,
        public string $password,
    ) {}

    /**
     * @return array{key: string, label: string, email: string, password: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
