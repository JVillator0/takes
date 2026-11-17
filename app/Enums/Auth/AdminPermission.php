<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * The console's custom permissions (A08): the audited write actions and the CSV export. Shield generates the
 * resource, page and widget permissions itself; these are declared here and seeded so they exist in every
 * environment. The names follow Shield 4's `Affix:Subject` keys.
 */
enum AdminPermission: string
{
    case AdjustCreditsCustomer = 'AdjustCredits:Customer';
    case RestrictCustomer = 'Restrict:Customer';
    case ReconcileTake = 'Reconcile:Take';
    case ExportReports = 'Export:Reports';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
