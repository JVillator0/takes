<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Queries\Webhooks\GetWebhookDeliveryStatsQuery;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header of Providers › Webhook deliveries (A11): the last 24 hours by outcome. Never polls (D-038).
 */
final class WebhookDeliveryStatsOverview extends StatsOverviewWidget
{
    use HasWidgetShield;

    protected ?string $pollingInterval = null;

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $stats = app(GetWebhookDeliveryStatsQuery::class)->execute();

        return [
            Stat::make(__('console.webhooks.deliveries_24h'), (string) $stats->events),
            Stat::make(__('console.webhooks.duplicates'), (string) $stats->duplicates),
            Stat::make(__('console.webhooks.ignored_stale'), (string) $stats->ignoredStale),
            Stat::make(__('console.webhooks.unknown_orphan'), $stats->unknownTake.' / '.$stats->orphanSuccess)
                ->color($stats->unknownTake + $stats->orphanSuccess > 0 ? 'danger' : 'success'),
        ];
    }
}
