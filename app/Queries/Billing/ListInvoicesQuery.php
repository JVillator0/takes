<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

/**
 * The customer's invoices for the billing page's Invoices tab (spec 6, P09),
 * newest first. One per purchase, so the list stays short.
 */
final readonly class ListInvoicesQuery
{
    /**
     * @return Collection<int, Invoice>
     */
    public function execute(Customer $customer): Collection
    {
        return Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('number')
            ->get();
    }
}
