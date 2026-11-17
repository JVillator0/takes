<?php

declare(strict_types=1);

namespace App\DTOs\Projects;

use App\Enums\Takes\TakeFilter;
use App\Models\Project;
use App\Models\Take;
use App\Support\Money\Nanodollars;

/**
 * One project's workspace (P04): the takes on screen (newest first, each
 * with its usage and number), whether older ones exist, the filter counts
 * and the project's spend.
 */
final readonly class ProjectWorkspaceDTO
{
    /**
     * @param  list<Take>  $takes
     * @param  array<value-of<TakeFilter>, int>  $counts
     */
    public function __construct(
        public Project $project,
        public array $takes,
        public bool $hasMore,
        public array $counts,
        public Nanodollars $spend,
        public TakeFilter $filter,
        public int $pages,
    ) {}

    /**
     * @param  array{project: Project, takes: list<Take>, hasMore: bool, counts: array<value-of<TakeFilter>, int>, spend: Nanodollars, filter: TakeFilter, pages: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['project'], $data['takes'], $data['hasMore'], $data['counts'], $data['spend'], $data['filter'], $data['pages']);
    }
}
