<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Enums\Takes\TakeStatus;
use App\Support\Pixelforge\GenerationId;
use App\Support\Pixelforge\Payload;
use Carbon\CarbonImmutable;

/**
 * A Pixelforge generation object as it was at one moment: the create
 * response, GET /v1/generations/{id}, a list item or a webhook's
 * `data.object`. Consumed by ApplyTakeSnapshotAction (spec 3-02).
 *
 * The provider statuses (queued, processing, succeeded, failed) are the
 * non-local TakeStatus cases; `pending` is local only and is rejected.
 * A snapshot that breaks the provider's own invariants (succeeded without
 * usage or image, failed without error) is rejected as invalid.
 *
 * @phpstan-import-type RatesPayload from RatesDTO
 * @phpstan-import-type UsageTokensPayload from UsageTokensDTO
 *
 * @phpstan-type GenerationPayload array{
 *     id: string,
 *     object: 'generation',
 *     status: 'queued'|'processing'|'succeeded'|'failed',
 *     prompt: string,
 *     size: string,
 *     quality: string,
 *     reference_images: list<string>,
 *     metadata: array<string, string>,
 *     image_url: string|null,
 *     image_url_expires_at: string|null,
 *     usage: UsageTokensPayload|null,
 *     rates: RatesPayload,
 *     error: array{code: string, message: string}|null,
 *     created_at: string,
 *     started_at: string|null,
 *     completed_at: string|null,
 * }
 */
final readonly class GenerationSnapshotDTO
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public TakeStatus $status,
        public ?UsageTokensDTO $usage,
        public ?RatesDTO $rates,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public array $metadata,
        public bool $replayed = false,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see GenerationPayload}
     * @param  bool  $replayed  `Idempotent-Replayed: true` on a create response
     */
    public static function fromArray(array $data, bool $replayed = false): self
    {
        $id = Payload::string($data, 'id');

        if (! GenerationId::isValid($id)) {
            throw Payload::invalid('id', 'a gen_ id');
        }

        $status = TakeStatus::tryFrom(Payload::string($data, 'status'));

        if ($status === null || $status === TakeStatus::Pending) {
            throw Payload::invalid('status', 'one of queued, processing, succeeded, failed');
        }

        $usage = Payload::nullableArray($data, 'usage');
        // `rates` is documented on every generation; if it is ever missing,
        // settlement fetches the generation first (spec 3-04).
        $rates = Payload::nullableArray($data, 'rates');
        $error = Payload::nullableArray($data, 'error');

        $snapshot = new self(
            id: $id,
            status: $status,
            usage: $usage === null ? null : UsageTokensDTO::fromArray($usage),
            rates: $rates === null ? null : RatesDTO::fromArray($rates),
            imageUrl: Payload::nullableString($data, 'image_url'),
            imageUrlExpiresAt: Payload::nullableTimestamp($data, 'image_url_expires_at'),
            errorCode: $error === null ? null : Payload::string($error, 'code'),
            errorMessage: $error === null ? null : Payload::string($error, 'message'),
            createdAt: Payload::timestamp($data, 'created_at'),
            startedAt: Payload::nullableTimestamp($data, 'started_at'),
            completedAt: Payload::nullableTimestamp($data, 'completed_at'),
            metadata: array_key_exists('metadata', $data) ? Payload::stringMap($data, 'metadata') : [],
            replayed: $replayed,
        );

        $snapshot->assertConsistent();

        return $snapshot;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /** Our take id, sent as metadata on create. */
    public function appTakeId(): ?string
    {
        return $this->metadata['app_take_id'] ?? null;
    }

    private function assertConsistent(): void
    {
        $succeeded = $this->status === TakeStatus::Succeeded;

        if ($succeeded !== ($this->usage !== null)) {
            throw Payload::invalid('usage', 'null until the generation succeeds, then present');
        }

        if ($succeeded && $this->imageUrl === null) {
            throw Payload::invalid('image_url', 'present on a succeeded generation');
        }

        if ($this->status === TakeStatus::Failed && $this->errorCode === null) {
            throw Payload::invalid('error', 'present on a failed generation');
        }

        if ($this->isTerminal() && $this->completedAt === null) {
            throw Payload::invalid('completed_at', 'present on a finished generation');
        }
    }
}
