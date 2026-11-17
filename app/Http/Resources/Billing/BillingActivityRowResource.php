<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\DTOs\Billing\BillingActivityRowDTO;
use App\Enums\Billing\UsageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * One Activity line (P08). Exact amounts (D-042); the status is a key the
 * client turns into 2-01 copy (F-17); no provider, cost or tokens (D-023).
 *
 * @property-read BillingActivityRowDTO $resource
 */
final class BillingActivityRowResource extends JsonResource
{
    /** The take drawer route (spec 4); the link appears once it exists. */
    private const string TAKE_ROUTE = 'projects.show';

    /**
     * @return array{key: string, kind: string, status: string|null, occurredAt: string, occurredAtLabel: string, amount: string|null, maxCharge: string|null, balance: string, detail: string|null, takeHref: string|null, invoice: array{number: string, href: string}|null}
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'key' => $row->kind->value.':'.$row->key,
            'kind' => $row->kind->value,
            'status' => match ($row->usageStatus) {
                UsageStatus::Open => 'on_hold',
                UsageStatus::Settled => 'charged',
                UsageStatus::Voided => 'not_charged',
                null => null,
            },
            'occurredAt' => $row->occurredAt->toIso8601ZuluString(),
            'occurredAtLabel' => $row->occurredAt->format('M j H:i'),
            'amount' => $row->amount === null ? null : MoneyFormat::signedExact($row->amount),
            'maxCharge' => $row->maxCharge?->formatExact(),
            'balance' => $row->balanceAfter->formatExact(),
            'detail' => $row->detail === null ? null : Str::limit($row->detail, 80),
            'takeHref' => $this->takeHref($row),
            'invoice' => $row->invoiceId === null || $row->invoiceNumber === null ? null : [
                'number' => $row->invoiceNumber,
                'href' => route('billing.invoices.show', $row->invoiceId),
            ],
        ];
    }

    private function takeHref(BillingActivityRowDTO $row): ?string
    {
        if ($row->takeId === null || $row->projectId === null || ! Route::has(self::TAKE_ROUTE)) {
            return null;
        }

        return route(self::TAKE_ROUTE, ['project' => $row->projectId, 'take' => $row->takeId]);
    }
}
