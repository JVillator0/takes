<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Pages;

use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\WebhookDeliveryResource;
use App\Filament\Admin\Widgets\WebhookDeliveryStatsOverview;
use Filament\Resources\Pages\ListRecords;

final class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            WebhookDeliveryStatsOverview::class,
        ];
    }
}
