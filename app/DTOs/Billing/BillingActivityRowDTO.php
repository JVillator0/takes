<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\Billing\BillingActivityKind;
use App\Enums\Billing\UsageStatus;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * One line of the billing page's Activity (spec 6): a usage (one line per
 * usage, whatever its status) or a non-charge ledger entry. `amount` is the
 * signed ledger amount (zero for a voided or $0 usage, null while on hold);
 * `balanceAfter` is Σ ledger up to that line, so it excludes open holds.
 */
final readonly class BillingActivityRowDTO
{
    public function __construct(
        public string $key,
        public BillingActivityKind $kind,
        public ?UsageStatus $usageStatus,
        public CarbonImmutable $occurredAt,
        public ?Nanodollars $amount,
        public ?Nanodollars $maxCharge,
        public Nanodollars $balanceAfter,
        public ?string $takeId,
        public ?string $projectId,
        public ?string $detail,
        public ?string $invoiceId,
        public ?string $invoiceNumber,
    ) {}

    /**
     * @param  array{row_key: string, kind: string, usage_status: string|null, occurred_at: string, amount_nanos: int|string|null, max_charge_nanos: int|string|null, balance_after: int|string, take_id: string|null, project_id: string|null, detail: string|null, invoice_id: string|null, invoice_number: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['usage_status'] === null ? null : UsageStatus::from($data['usage_status']);

        $amount = match (true) {
            $data['amount_nanos'] !== null => Nanodollars::fromInt($data['amount_nanos']),
            // A voided usage, or one settled for $0 (no ledger entry), moved
            // no money: shown as $0.00, never hidden.
            $status === UsageStatus::Voided, $status === UsageStatus::Settled => Nanodollars::zero(),
            default => null,
        };

        return new self(
            key: trim($data['row_key']),
            kind: BillingActivityKind::from($data['kind']),
            usageStatus: $status,
            occurredAt: CarbonImmutable::parse($data['occurred_at'])->utc(),
            amount: $amount,
            maxCharge: $data['max_charge_nanos'] === null ? null : Nanodollars::fromInt($data['max_charge_nanos']),
            balanceAfter: Nanodollars::fromInt($data['balance_after']),
            takeId: $data['take_id'] === null ? null : trim($data['take_id']),
            projectId: $data['project_id'] === null ? null : trim($data['project_id']),
            detail: $data['detail'],
            invoiceId: $data['invoice_id'] === null ? null : trim($data['invoice_id']),
            invoiceNumber: $data['invoice_number'],
        );
    }
}
