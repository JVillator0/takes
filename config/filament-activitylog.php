<?php

declare(strict_types=1);

use App\Filament\Admin\Audit\AdminAuditResource;

return [
    'resources' => [
        // Fallbacks only: the panel sets both labels from lang/en/console.php (console.audit.*).
        'label' => 'Admin audit entry',
        'plural_label' => 'Activity log',
        'hide_restore_action' => true,
        'restore_action_label' => 'Restore',
        'hide_resource_action' => true,
        'hide_restore_model_action' => true,
        'resource_action_label' => 'View',
        'navigation_item' => true,
        'navigation_group' => null,
        'navigation_icon' => 'heroicon-o-shield-check',
        'navigation_sort' => null,
        'default_sort_column' => 'id',
        'default_sort_direction' => 'desc',
        'navigation_count_badge' => false,
        'resource' => AdminAuditResource::class,
    ],
    'date_format' => 'Y-m-d',
    'datetime_format' => 'Y-m-d H:i:s \U\T\C',
];
