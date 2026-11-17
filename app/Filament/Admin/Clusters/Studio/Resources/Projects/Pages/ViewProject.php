<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Projects\Pages;

use App\Filament\Admin\Clusters\Studio\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
}
