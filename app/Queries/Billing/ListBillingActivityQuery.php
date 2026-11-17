<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\BillingActivityPageDTO;
use App\DTOs\Billing\BillingActivityRowDTO;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * The billing page's Activity (spec 6, P08), newest first:
 *
 * - one line per usage record, whatever its status (open = on hold, settled =
 *   charged, voided = not charged), with its feature and take. A charged
 *   usage sits at its charge entry, one settled for $0 (no entry, see
 *   SettleUsageAction) at its completion, open and voided ones at creation.
 * - one line per `purchase`, `promo_grant` and `adjustment` ledger entry.
 *   Charges only appear through their usage; holds are never lines (D-024).
 *
 * The running balance is one window sum over that single stream, in ledger
 * order, where only ledger entries move it: Σ ledger up to the line, so it
 * excludes open holds. Each page sorts the customer's whole history once
 * (cost noted in the README's design notes).
 */
final readonly class ListBillingActivityQuery
{
    public const int PER_PAGE = 25;

    public function execute(Customer $customer, int $page = 1, int $perPage = self::PER_PAGE): BillingActivityPageDTO
    {
        $page = max(1, $page);
        $take = (new Take)->getMorphClass();
        $suggestion = (new Suggestion)->getMorphClass();

        $rows = DB::select(<<<'SQL'
            WITH events AS (
                SELECT
                    CASE WHEN ur.billable_type = ? THEN 'image' ELSE 'ai_suggestion' END AS kind,
                    ur.id::text AS row_key,
                    ur.status AS usage_status,
                    -- Charged: at the charge entry. Settled for $0 (no entry): at completion.
                    -- Open or voided: at creation.
                    CASE
                        WHEN charge.id IS NOT NULL THEN charge.created_at
                        WHEN ur.status = 'settled' THEN COALESCE(ur.completed_at, ur.updated_at)
                        ELSE ur.created_at
                    END AS occurred_at,
                    -- Lines without a ledger entry sort after the entries at the same instant.
                    CASE WHEN charge.id IS NULL THEN 1 ELSE 0 END AS tie_rank,
                    COALESCE(charge.id, 0) AS ledger_id,
                    charge.amount_nanos AS amount_nanos,
                    COALESCE(charge.amount_nanos, 0) AS balance_delta,
                    ur.max_charge_nanos AS max_charge_nanos,
                    COALESCE(t.id, s.base_take_id)::text AS take_id,
                    COALESCE(t.project_id, s.project_id)::text AS project_id,
                    COALESCE(t.prompt, s.instruction) AS detail,
                    NULL::text AS invoice_id,
                    NULL::text AS invoice_number
                FROM usage_records ur
                LEFT JOIN ledger_entries charge ON charge.usage_record_id = ur.id AND charge.type = 'charge'
                LEFT JOIN takes t ON ur.billable_type = ? AND t.id = ur.billable_id
                LEFT JOIN suggestions s ON ur.billable_type = ? AND s.id = ur.billable_id
                WHERE ur.customer_id = ?

                UNION ALL

                SELECT
                    CASE
                        WHEN l.type = 'purchase' THEN 'purchase'
                        WHEN l.type = 'adjustment' THEN 'adjustment'
                        WHEN l.reason = 'welcome' THEN 'welcome_credit'
                        ELSE 'promo_grant'
                    END,
                    l.id::text,
                    NULL,
                    l.created_at,
                    0,
                    l.id,
                    l.amount_nanos,
                    l.amount_nanos,
                    NULL,
                    NULL,
                    NULL,
                    NULL,
                    i.id::text,
                    i.number
                FROM ledger_entries l
                LEFT JOIN invoices i ON i.purchase_id = l.purchase_id
                WHERE l.customer_id = ? AND l.type <> 'charge'
            ),
            activity AS (
                -- One pass: Σ ledger up to each line, in ledger order (created_at, id).
                SELECT *,
                       SUM(balance_delta) OVER (
                           ORDER BY occurred_at, tie_rank, ledger_id, row_key
                           ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                       ) AS balance_after
                FROM events
            )
            SELECT kind, row_key, usage_status, occurred_at, amount_nanos, max_charge_nanos, balance_after,
                   take_id, project_id, detail, invoice_id, invoice_number
            FROM activity
            ORDER BY occurred_at DESC, tie_rank DESC, ledger_id DESC, row_key DESC
            LIMIT ? OFFSET ?
            SQL, [
            $take,
            $take,
            $suggestion,
            $customer->id,
            $customer->id,
            $perPage + 1,
            ($page - 1) * $perPage,
        ]);

        $mapped = array_map(
            fn (object $row): BillingActivityRowDTO => BillingActivityRowDTO::fromArray(self::row((array) $row)),
            array_slice($rows, 0, $perPage),
        );

        return BillingActivityPageDTO::fromArray([
            'rows' => array_values($mapped),
            'page' => $page,
            'has_more' => count($rows) > $perPage,
        ]);
    }

    /**
     * Narrows one raw row to the DTO's shape. Postgres returns bigint as int
     * and the window sum (numeric) as an integer string (money rule M-04).
     *
     * @param  array<array-key, mixed>  $row
     * @return array{row_key: string, kind: string, usage_status: string|null, occurred_at: string, amount_nanos: int|string|null, max_charge_nanos: int|string|null, balance_after: int|string, take_id: string|null, project_id: string|null, detail: string|null, invoice_id: string|null, invoice_number: string|null}
     */
    private static function row(array $row): array
    {
        $string = fn (string $key): string => is_string($row[$key] ?? null)
            ? $row[$key]
            : throw new UnexpectedValueException("Activity column [{$key}] must be a string.");
        $nullableString = fn (string $key): ?string => ($row[$key] ?? null) === null ? null : $string($key);
        $integer = fn (string $key): int|string => is_int($row[$key] ?? null) || is_string($row[$key] ?? null)
            ? $row[$key]
            : throw new UnexpectedValueException("Activity column [{$key}] must be an integer.");
        $nullableInteger = fn (string $key): int|string|null => ($row[$key] ?? null) === null ? null : $integer($key);

        return [
            'row_key' => $string('row_key'),
            'kind' => $string('kind'),
            'usage_status' => $nullableString('usage_status'),
            'occurred_at' => $string('occurred_at'),
            'amount_nanos' => $nullableInteger('amount_nanos'),
            'max_charge_nanos' => $nullableInteger('max_charge_nanos'),
            'balance_after' => $integer('balance_after'),
            'take_id' => $nullableString('take_id'),
            'project_id' => $nullableString('project_id'),
            'detail' => $nullableString('detail'),
            'invoice_id' => $nullableString('invoice_id'),
            'invoice_number' => $nullableString('invoice_number'),
        ];
    }
}
