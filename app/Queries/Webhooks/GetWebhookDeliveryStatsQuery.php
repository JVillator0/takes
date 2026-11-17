<?php

declare(strict_types=1);

namespace App\Queries\Webhooks;

use App\DTOs\Webhooks\WebhookDeliveryStatsDTO;
use App\Enums\Webhooks\WebhookOutcome;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;

/**
 * The last 24 hours of webhooks (spec 7 § Providers › Webhook events): events = count of `webhook_events`; the
 * other figures count `webhook_deliveries` by outcome, the only place an outcome lives (D-027).
 */
final readonly class GetWebhookDeliveryStatsQuery
{
    public function execute(): WebhookDeliveryStatsDTO
    {
        $since = CarbonImmutable::now()->subDay();

        $outcomes = WebhookDelivery::query()
            ->where('received_at', '>=', $since)
            ->toBase()
            ->selectRaw('count(*) filter (where outcome = ?) as duplicates', [WebhookOutcome::Duplicate->value])
            ->selectRaw('count(*) filter (where outcome = ?) as ignored_stale', [WebhookOutcome::IgnoredStale->value])
            ->selectRaw('count(*) filter (where outcome = ?) as unknown_take', [WebhookOutcome::UnknownTake->value])
            ->selectRaw('count(*) filter (where outcome = ?) as orphan_success', [WebhookOutcome::OrphanSuccess->value])
            ->first();

        return WebhookDeliveryStatsDTO::fromArray([
            'events' => WebhookEvent::query()->where('received_at', '>=', $since)->count(),
            'duplicates' => (int) ($outcomes->duplicates ?? 0),
            'ignored_stale' => (int) ($outcomes->ignored_stale ?? 0),
            'unknown_take' => (int) ($outcomes->unknown_take ?? 0),
            'orphan_success' => (int) ($outcomes->orphan_success ?? 0),
        ]);
    }
}
