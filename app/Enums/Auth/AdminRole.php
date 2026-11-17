<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * Roles on the `web` guard: the admins of the console (spec 7, A08, D-012, D-013).
 *
 * `SuperAdmin` may do everything through a `Gate::before` in the panel provider, so it holds no permission rows.
 * `Operator` reads everything and runs the audited write actions. `Auditor` only reads (the evaluator's demo login)
 * and is the one role exempt from multi-factor authentication.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Operator = 'operator';
    case Auditor = 'auditor';

    public const string GUARD = 'web';

    public function requiresMultiFactorAuthentication(): bool
    {
        return $this !== self::Auditor;
    }

    /**
     * The permissions this role holds, picked from the panel's Shield permission keys (`ViewAny:Take`,
     * `View:Reports`, ...) plus the custom ones in AdminPermission. Read roles keep only the view keys, so a
     * resource or page added later is readable by them without a code change, and nothing they hold can write.
     *
     * @param  list<string>  $panelPermissions
     * @return list<string>
     */
    public function permissions(array $panelPermissions): array
    {
        $viewPermissions = array_values(array_filter(
            $panelPermissions,
            fn (string $permission): bool => str_starts_with($permission, 'ViewAny:') || str_starts_with($permission, 'View:'),
        ));

        return match ($this) {
            self::SuperAdmin => [],
            self::Operator => [...$viewPermissions, ...AdminPermission::values()],
            self::Auditor => $viewPermissions,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
