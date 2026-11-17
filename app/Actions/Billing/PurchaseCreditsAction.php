<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\PurchaseStatus;
use App\Exceptions\Billing\IdempotencyKeyReusedException;
use App\Models\Customer;
use App\Models\Purchase;
use App\Support\Money\Nanodollars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A simulated credit purchase (spec 3-03 §5, D-051): a `paid` purchase, one
 * `purchase` ledger entry and its invoice (spec 6) in one transaction.
 * Idempotent by (customer, idempotency key): a replay returns the original
 * purchase and creates nothing. Lock order: customer → invoice counter.
 */
final readonly class PurchaseCreditsAction
{
    public function __construct(
        private RecordLedgerEntryAction $recordLedgerEntry,
        private IssueInvoiceAction $issueInvoice,
    ) {}

    /**
     * @throws IdempotencyKeyReusedException when the key was already used for another pack
     */
    public function execute(Customer $customer, int $packageUsd, string $idempotencyKey): Purchase
    {
        /** @var list<int> $packs */
        $packs = config('takes.purchase_packs_usd');

        if (! in_array($packageUsd, $packs, true)) {
            throw new InvalidArgumentException("[{$packageUsd}] is not a credit pack.");
        }

        return DB::transaction(function () use ($customer, $packageUsd, $idempotencyKey): Purchase {
            // Same-key requests serialize on the customer lock, so the second
            // one finds the first one's purchase instead of racing it.
            Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $existing = Purchase::query()
                ->where('customer_id', $customer->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            // Whole dollars × 10^9, integer math only (D-002).
            $amount = Nanodollars::fromInt($packageUsd * Nanodollars::PER_DOLLAR);

            if ($existing !== null) {
                // A replay is the same request: a key reused for another pack
                // is a client bug, never a silent success for the first pack.
                return $existing->amount_nanos === $amount->toInt()
                    ? $existing
                    : throw new IdempotencyKeyReusedException($idempotencyKey);
            }

            $purchase = new Purchase;
            $purchase->forceFill([
                'customer_id' => $customer->id,
                // Simulated payment reference shown on the invoice (spec 6).
                'reference' => 'SIM-'.Str::ulid(),
                'amount_nanos' => $amount->toInt(),
                'status' => PurchaseStatus::Paid,
                'paid_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ])->save();

            $this->recordLedgerEntry->execute(
                customer: $customer,
                type: LedgerEntryType::Purchase,
                amount: $amount,
                purchase: $purchase,
            );

            $purchase->setRelation('invoice', $this->issueInvoice->execute($purchase));

            return $purchase;
        });
    }
}
