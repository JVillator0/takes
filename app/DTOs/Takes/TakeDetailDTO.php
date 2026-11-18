<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

use App\Models\Take;

/**
 * One take with what its drawer shows (P06): the parent, the version strip
 * (ancestors, this take, its remixes, in creation order) and the reference
 * images as URLs that load now (take images re-issued, dead ones left out).
 * Every take carries its `number` attribute.
 */
final readonly class TakeDetailDTO
{
    /**
     * @param  list<Take>  $versions
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public Take $take,
        public ?Take $parent,
        public array $versions,
        public array $referenceImages = [],
    ) {}

    /**
     * @param  array{take: Take, parent?: Take|null, versions?: list<Take>, reference_images?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['take'], $data['parent'] ?? null, $data['versions'] ?? [], $data['reference_images'] ?? []);
    }
}
