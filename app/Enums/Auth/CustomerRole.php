<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * Roles on the `customer` guard (spec 3-01). `Customer` is the default on
 * registration; an operator switches an account to `Restricted` (spec 7).
 */
enum CustomerRole: string
{
    case Customer = 'customer';
    case Restricted = 'restricted';

    public const string GUARD = 'customer';

    /**
     * @return list<CustomerPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Customer => CustomerPermission::cases(),
            self::Restricted => [
                CustomerPermission::CreditsPurchase,
                CustomerPermission::ProjectsManage,
            ],
        };
    }
}
