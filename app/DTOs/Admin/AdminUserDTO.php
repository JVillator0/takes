<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

use App\Enums\Auth\AdminRole;

/**
 * An admin as the System › Admins form submits it: name, email, the new password (null on an edit that keeps the
 * current one), the console roles and the required audit reason (FI-08: access changes carry a reason).
 */
final readonly class AdminUserDTO
{
    /**
     * @param  list<AdminRole>  $roles
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password,
        public array $roles,
        public string $reason,
    ) {}

    /**
     * @param  array{name: string, email: string, password?: string|null, roles: list<string|AdminRole>, reason: string}  $data
     */
    public static function fromArray(array $data): self
    {
        $password = $data['password'] ?? null;

        return new self(
            trim($data['name']),
            mb_strtolower(trim($data['email'])),
            is_string($password) && $password !== '' ? $password : null,
            array_values(array_unique(array_map(
                fn (string|AdminRole $role): AdminRole => $role instanceof AdminRole ? $role : AdminRole::from($role),
                $data['roles'],
            ), SORT_REGULAR)),
            trim($data['reason']),
        );
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_map(fn (AdminRole $role): string => $role->value, $this->roles);
    }
}
