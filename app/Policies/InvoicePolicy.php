<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\Invoice;
use App\Policies\Concerns\AuthorizesCustomersOnly;
use Illuminate\Auth\Access\Response;

/**
 * Invoices are visible to their owner only (spec 6). Ownership only, no
 * permission, so the read-only showcase (D-072) still sees its history.
 * Another customer's invoice is a 404, never a 403: no existence leak.
 */
final readonly class InvoicePolicy
{
    use AuthorizesCustomersOnly;

    public function view(Customer $customer, Invoice $invoice): Response
    {
        return $invoice->customer_id === $customer->id ? Response::allow() : Response::denyAsNotFound();
    }
}
