<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes\RelationManagers;

use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Tables\WebhookDeliveriesTable;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A06 "Webhook deliveries": every delivery for this take with attempt and outcome, `orphan_success` in red, and
 * the raw payload. Shown to admins who may read webhook deliveries.
 */
final class WebhookDeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'webhookDeliveries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('console.webhooks.plural');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User && $admin->can('ViewAny:WebhookDelivery');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return WebhookDeliveriesTable::configure($table, showTake: false)
            ->modifyQueryUsing(fn ($query) => $query->with('webhookEvent'));
    }
}
