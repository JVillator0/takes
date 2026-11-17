<?php

declare(strict_types=1);

namespace App\Enums\Auth;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Roles on the `customer` guard (spec 3-01). `Customer` is the default on
 * registration; an operator switches an account to `Restricted` (spec 7).
 * `Showcase` is the public showcase login's read-only role (D-072): it can
 * view its own projects and takes (ownership only) and nothing else.
 */
enum CustomerRole: string implements HasColor, HasLabel
{
    case Customer = 'customer';
    case Restricted = 'restricted';
    case Showcase = 'showcase';

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
            self::Showcase => [],
        };
    }

    /** Admin console label (Filament HasLabel), from lang/en/console.php. */
    public function getLabel(): string
    {
        $label = __('console.enums.customer_role.'.$this->value);

        return is_string($label) ? $label : $this->value;
    }

    /** Admin console badge color (Filament HasColor). */
    public function getColor(): string
    {
        return match ($this) {
            self::Customer => 'success',
            self::Restricted => 'danger',
            self::Showcase => 'gray',
        };
    }
}
