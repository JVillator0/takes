<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes\Pages;

use App\Filament\Admin\Clusters\Studio\Resources\Takes\Actions\ReconcileTakeAction;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Models\Take;
use Filament\Resources\Pages\ViewRecord;

/**
 * A06: one take, to debug it. Cluster sub-navigation sits in the page header, not under the title.
 */
final class ViewTake extends ViewRecord
{
    protected static string $resource = TakeResource::class;

    public function getTitle(): string
    {
        $take = $this->getRecord();

        return __('console.takes.title', ['id' => $take instanceof Take ? $take->id : '']);
    }

    protected function getHeaderActions(): array
    {
        return [
            ReconcileTakeAction::make(),
        ];
    }
}
