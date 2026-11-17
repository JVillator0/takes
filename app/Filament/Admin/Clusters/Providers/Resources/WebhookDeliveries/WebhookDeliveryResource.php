<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries;

use App\Filament\Admin\Clusters\Providers\ProvidersCluster;
use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Tables\WebhookDeliveriesTable;
use App\Filament\Admin\Concerns\ReadsWithShieldPermissions;
use App\Models\WebhookDelivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Providers › Webhook deliveries (A11, spec 7 "Webhook events"): one row per verified delivery joined to its
 * event (D-027). Read-only; the payload opens in a modal.
 */
final class WebhookDeliveryResource extends Resource
{
    use ReadsWithShieldPermissions;

    protected static ?string $model = WebhookDelivery::class;

    protected static ?string $cluster = ProvidersCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $slug = 'webhook-deliveries';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('console.webhooks.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('console.webhooks.plural');
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    public static function getEloquentQuery(): Builder
    {
        return WebhookDelivery::query()->with('webhookEvent');
    }

    public static function table(Table $table): Table
    {
        return WebhookDeliveriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookDeliveries::route('/'),
        ];
    }
}
