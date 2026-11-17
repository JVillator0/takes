<?php

declare(strict_types=1);

namespace App\DTOs\Webhooks;

use App\DTOs\Pixelforge\GenerationSnapshotDTO;
use App\Enums\Webhooks\PixelforgeEventType;
use App\Support\Pixelforge\Payload;
use InvalidArgumentException;

/**
 * One verified Pixelforge webhook delivery (spec 5). The envelope (`id`,
 * `type`) must be readable or the request is a 400 (W-04). The generation in
 * `data.object` is parsed here too, but a malformed one does not reject the
 * delivery: the handler records it as `invalid_snapshot` (W-13), keeping the
 * ids needed to find the take.
 *
 * @phpstan-type WebhookPayload array{
 *     id: string,
 *     object: 'event',
 *     type: string,
 *     created_at: string,
 *     data: array{object?: array<string, mixed>},
 * }
 */
final readonly class PixelforgeWebhookDTO
{
    /**
     * @param  array<string, mixed>  $payload  the decoded body, stored on webhook_events
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public ?PixelforgeEventType $eventType,
        public int $attempt,
        public ?string $generationId,
        public ?string $appTakeId,
        public ?GenerationSnapshotDTO $snapshot,
        public ?string $snapshotError,
        public array $payload,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see WebhookPayload}
     * @param  int  $attempt  `Pixelforge-Delivery-Attempt` (1 for the first try)
     *
     * @throws InvalidArgumentException when the envelope cannot be read (400)
     */
    public static function fromArray(array $data, int $attempt = 1): self
    {
        $eventId = Payload::string($data, 'id');

        if (! str_starts_with($eventId, 'evt_')) {
            throw Payload::invalid('id', 'an evt_ id');
        }

        $type = Payload::string($data, 'type');
        $eventType = PixelforgeEventType::tryFrom($type);
        $object = is_array($data['data'] ?? null) ? ($data['data']['object'] ?? null) : null;
        $object = is_array($object) ? $object : null;

        $snapshot = null;
        $snapshotError = null;

        if ($eventType?->isGeneration() === true) {
            try {
                $snapshot = $object === null
                    ? throw Payload::invalid('data.object', 'a generation object')
                    : GenerationSnapshotDTO::fromArray($object);
            } catch (InvalidArgumentException $exception) {
                $snapshotError = $exception->getMessage();
            }
        }

        $generationId = $object['id'] ?? null;
        $metadata = $object['metadata'] ?? null;
        $appTakeId = is_array($metadata) ? ($metadata['app_take_id'] ?? null) : null;

        /** @var array<string, mixed> $data */
        return new self(
            eventId: $eventId,
            type: $type,
            eventType: $eventType,
            attempt: max(1, $attempt),
            generationId: is_string($generationId) && $generationId !== '' ? $generationId : null,
            appTakeId: is_string($appTakeId) && $appTakeId !== '' ? $appTakeId : null,
            snapshot: $snapshot,
            snapshotError: $snapshotError,
            payload: $data,
        );
    }
}
