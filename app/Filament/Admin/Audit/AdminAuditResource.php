<?php

declare(strict_types=1);

namespace App\Filament\Admin\Audit;

use Rmsramos\Activitylog\Resources\Activitylog\ActivitylogResource;

/**
 * The activity-log plugin's resource with the console's own sidebar label (lang `console.audit.title`, "Activity
 * log"): the plugin title-cases its label. Registered through the plugin (`->resource()`), outside the discovered
 * Resources directory so it is not registered twice.
 */
final class AdminAuditResource extends ActivitylogResource
{
    public static function getNavigationLabel(): string
    {
        return __('console.audit.title');
    }
}
