<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Support\Pixelforge\Payload;

/**
 * One page of GET /v1/generations (newest first). Pass `nextCursor` back
 * to get the next page; it is null on the last one (D-034).
 *
 * Parsing is all or nothing, on purpose. A page that says `has_more` with
 * no cursor is invalid: ending the loop there would silently drop the
 * remaining pages. One item that is not a valid generation makes the whole
 * page invalid too, instead of being skipped: the drill-down diffs every
 * provider generation against our takes (theirs-not-ours, D-034), so a
 * skipped item could be the one it is looking for. The client reports
 * either case as PixelforgeUnavailable (unreadable); the caller retries.
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

        if ($hasMore && ($nextCursor === null || $nextCursor === '')) {
            throw Payload::invalid('next_cursor', 'a non-empty string while has_more is true');
        }

        return new self(
            generations: $generations,
            hasMore: $hasMore,
            // A cursor only means something while there are more pages.
            nextCursor: $hasMore ? $nextCursor : null,
        );
    }
}
