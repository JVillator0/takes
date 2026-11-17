<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\DTOs\Billing\BillingSummaryDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Billing page cards (P08). A money page: every amount is exact (D-042),
 * spend is by feature only (D-023).
 *
 * @property-read BillingSummaryDTO $resource
 */
final class BillingSummaryResource extends JsonResource
{
    /**
     * @return array{available: string, balance: string, onHold: string, spent: array{month: string, total: string, images: string, aiSuggestions: string}}
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'available' => $summary->available->formatExact(),
            'balance' => $summary->balance->formatExact(),
            'onHold' => $summary->held->formatExact(),
            'spent' => [
                'month' => $summary->monthStart->format('F Y'),
                'total' => $summary->spentTotal()->formatExact(),
                'images' => $summary->spentImages->formatExact(),
                'aiSuggestions' => $summary->spentAiSuggestions->formatExact(),
            ],
        ];
    }
}
