<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\DTOs\Billing\CreditPackDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credit pack for the billing page and its purchase dialog (P08). Exact
 * strings (D-042): the client shows them and never adds them up (F-12).
 *
 * @property-read CreditPackDTO $resource
 */
final class CreditPackResource extends JsonResource
{
    /**
     * @return array{id: string, amount: string, tax: string, total: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'amount' => $this->resource->amount->formatExact(),
            'tax' => $this->resource->tax->formatExact(),
            'total' => $this->resource->total->formatExact(),
        ];
    }
}
