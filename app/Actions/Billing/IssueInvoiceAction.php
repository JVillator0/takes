<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PurchaseStatus;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Issues the invoice of one paid purchase (spec 6, D-022), in the caller's
 * transaction, idempotent per purchase. Numbers are `INV-{YYYY}-{NNNNNN}`,
 * gapless per UTC year of the payment.
 *
 * Lock order (the only one): customer row → invoice counter row. The counter
 * row stays locked until the caller commits, so purchases of every customer
 * serialise on it for the length of their transaction (acceptable at this
 * scale). A rolled-back purchase rolls its increment back too: no gaps.
 */
final readonly class IssueInvoiceAction
{
    public function execute(Purchase $purchase): Invoice
    {
        TransactionRequiredException::unlessInTransaction(self::class);

        if ($purchase->status !== PurchaseStatus::Paid || $purchase->paid_at === null) {
            throw new InvalidArgumentException("Purchase [{$purchase->id}] is not paid; only paid purchases are invoiced.");
        }

        // Customer first: a no-op when PurchaseCreditsAction already holds it,
        // and what serialises two issues of the same purchase.
        $customer = Customer::query()->lockForUpdate()->findOrFail($purchase->customer_id);

        $existing = Invoice::query()->where('purchase_id', $purchase->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $issuedAt = $purchase->paid_at->utc();
        $year = $issuedAt->year;

        // The first purchase of a year creates the row; a concurrent first
        // purchase waits on the unique key, then does nothing.
        DB::statement(
            'INSERT INTO invoice_counters (year, last_number) VALUES (?, 0) ON CONFLICT (year) DO NOTHING',
            [$year],
        );

        // One statement locks the row, increments it and reads the new value.
        $number = DB::scalar(
            'UPDATE invoice_counters SET last_number = last_number + 1 WHERE year = ? RETURNING last_number',
            [$year],
        );

        if (! is_int($number)) {
            throw new UnexpectedValueException("The invoice counter for {$year} returned no number.");
        }

        $invoice = new Invoice;
        $invoice->forceFill([
            'number' => sprintf('INV-%04d-%06d', $year, $number),
            'customer_id' => $customer->id,
            'purchase_id' => $purchase->id,
            'currency' => 'USD',
            'total_nanos' => $purchase->amount_nanos,
            'status' => InvoiceStatus::Paid,
            'issued_at' => $issuedAt,
            'billed_name' => $customer->name,
            'billed_email' => $customer->email,
        ])->save();

        return $invoice;
    }
}
