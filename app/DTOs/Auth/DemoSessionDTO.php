<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Models\Customer;
use App\Models\Project;

/** A fresh ephemeral demo customer and the project it lands in (D-045). */
final readonly class DemoSessionDTO
{
    public function __construct(
        public Customer $customer,
        public Project $project,
    ) {}

    /**
     * @param  array{customer: Customer, project: Project}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['customer'], $data['project']);
    }
}
