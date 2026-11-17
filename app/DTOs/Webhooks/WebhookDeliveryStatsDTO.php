<?php

declare(strict_types=1);

namespace App\DTOs\Webhooks;

/**
 * Header stats of Providers › Webhook deliveries (A11, spec 7): events received in the last 24 hours (one row per
 * `event_id`) and, among the deliveries of that window, how many were duplicates, stale, for an unknown take or
 * an orphan success.
 */
final readonly class WebhookDeliveryStatsDTO
{
    public function __construct(
        public int $events,
        public int $duplicates,
        public int $ignoredStale,
        public int $unknownTake,
        public int $orphanSuccess,
    ) {}

    /**
     * @param  array{events: int, duplicates: int, ignored_stale: int, unknown_take: int, orphan_success: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['events'], $data['duplicates'], $data['ignored_stale'], $data['unknown_take'], $data['orphan_success']);
    }
}
