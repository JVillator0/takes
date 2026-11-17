<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Projects\Schemas;

use App\Filament\Admin\Support\Money;
use App\Models\Project;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name')->label(__('console.projects.name')),
                    TextEntry::make('customer.name')->label(__('console.common.customer')),
                    TextEntry::make('takes_count')->label(__('console.projects.takes')),
                    TextEntry::make('spend_nanos')->label(__('console.projects.spend'))
                        ->state(fn (Project $record): string => Money::exact($record->getAttribute('spend_nanos') ?? 0)),
                    TextEntry::make('created_at')->label(__('console.common.created'))->dateTime('Y-m-d H:i:s \U\T\C'),
                    TextEntry::make('archived_at')->label(__('console.projects.archived'))->dateTime('Y-m-d H:i:s \U\T\C')->placeholder('-'),
                ]),
        ]);
    }
}
