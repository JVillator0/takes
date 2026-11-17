<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

use App\Models\Take;

/**
 * One take with what its drawer shows (P06): the parent and the version
 * strip (ancestors, this take, its remixes, in creation order). Every take
 * carries its `number` attribute.
 */
final readonly class TakeDetailDTO
{
    /**
     * @param  list<Take>  $versions
     */
    public function __construct(
        public Take $take,
        public ?Take $parent,
        public array $versions,
    ) {}

    /**
     * @param  array{take: Take, parent?: Take|null, versions?: list<Take>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['take'], $data['parent'] ?? null, $data['versions'] ?? []);
    }
}
