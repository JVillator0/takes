<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Projects\Pages;

use App\Filament\Admin\Clusters\Studio\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ListRecords;

final class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;
}
