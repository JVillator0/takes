<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Invoice;
use App\Support\Money\Nanodollars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice for the Invoices tab and the printable view (P09). The total is
 * exact (D-042). `detail` adds what only the printable view shows.
 *
 * @property-read Invoice $resource
 */
final class InvoiceResource extends JsonResource
{
    /**
     * @return array{id: string, number: string, status: string, issuedAt: string, issuedAtLabel: string, total: string, href: string}
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'issuedAt' => $invoice->issued_at->utc()->toIso8601ZuluString(),
            'issuedAtLabel' => $invoice->issued_at->utc()->format('M j, Y'),
            'total' => $invoice->total()->formatExact(),
            'href' => route('billing.invoices.show', $invoice),
        ];
    }

    /**
     * The printable invoice: one line (the purchase), no tax (spec 6).
     *
     * @return array{id: string, number: string, status: string, issuedAt: string, issuedAtLabel: string, total: string, href: string, currency: string, billedName: string, billedEmail: string, reference: string, quantity: int, unitPrice: string, tax: string}
     */
    public function detail(Request $request): array
    {
        $invoice = $this->resource;

        return [
            ...$this->toArray($request),
            'currency' => $invoice->currency,
            'billedName' => $invoice->billed_name,
            'billedEmail' => $invoice->billed_email,
            'reference' => $invoice->purchase->reference,
            'quantity' => 1,
            'unitPrice' => $invoice->total()->formatExact(),
            'tax' => Nanodollars::zero()->formatExact(),
        ];
    }
}
