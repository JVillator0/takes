<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

/**
 * One page of Activity, newest first. Simple pagination: the query reads one
 * extra row to know whether earlier entries exist.
 */
final readonly class BillingActivityPageDTO
{
    /**
     * @param  list<BillingActivityRowDTO>  $rows
     */
    public function __construct(
        public array $rows,
        public int $page,
        public bool $hasMore,
    ) {}

    /**
     * @param  array{rows: list<BillingActivityRowDTO>, page: int, has_more: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['rows'], $data['page'], $data['has_more']);
    }
}
