<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes\Pages;

use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Filament\Admin\Widgets\TakeStatsOverview;
use Filament\Resources\Pages\ListRecords;

final class ListTakes extends ListRecords
{
    protected static string $resource = TakeResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            TakeStatsOverview::class,
        ];
    }
}
