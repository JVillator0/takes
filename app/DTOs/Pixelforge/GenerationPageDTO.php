<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Support\Pixelforge\Payload;

/**
 * One page of GET /v1/generations (newest first). Pass `nextCursor` back
 * to get the next page; it is null on the last one (D-034).
 *
 * @phpstan-import-type GenerationPayload from GenerationSnapshotDTO
 *
 * @phpstan-type GenerationPagePayload array{
 *     object: 'list',
 *     data: list<GenerationPayload>,
 *     has_more: bool,
 *     next_cursor: string|null,
 * }
 */
final readonly class GenerationPageDTO
{
    /**
     * @param  list<GenerationSnapshotDTO>  $generations
     */
    public function __construct(
        public array $generations,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}

    /**
     * @param  array<mixed>  $data  a {@see GenerationPagePayload}
     */
    public static function fromArray(array $data): self
    {
        $generations = [];

        foreach (Payload::array($data, 'data') as $item) {
            if (! is_array($item)) {
                throw Payload::invalid('data', 'a list of generation objects');
            }

            $generations[] = GenerationSnapshotDTO::fromArray($item);
        }

        $hasMore = Payload::bool($data, 'has_more');
        $nextCursor = Payload::nullableString($data, 'next_cursor');

        return new self(
            generations: $generations,
            hasMore: $hasMore,
            // A cursor only means something while there are more pages.
            nextCursor: $hasMore && $nextCursor !== '' ? $nextCursor : null,
        );
    }
}
