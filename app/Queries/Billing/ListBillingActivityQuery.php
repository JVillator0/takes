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
 *   charged, voided = not charged), with its feature and take. A settled
 *   usage sits at its charge entry; open and voided ones at their creation.
 * - one line per `purchase`, `promo_grant` and `adjustment` ledger entry.
 *   Charges only appear through their usage; holds are never lines (D-024).
 *
 * The running balance is Σ ledger up to that line (window over
 * `created_at, id`), so it excludes open holds. A usage line without a
 * charge shows the balance of the last entry at or before it.
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
            WITH ledger AS (
                SELECT id, type, amount_nanos, usage_record_id, purchase_id, reason, created_at,
                       SUM(amount_nanos) OVER (ORDER BY created_at, id) AS balance_after
                FROM ledger_entries
                WHERE customer_id = ?
            ),
            activity AS (
                SELECT
                    CASE WHEN ur.billable_type = ? THEN 'image' ELSE 'ai_suggestion' END AS kind,
                    ur.id::text AS row_key,
                    ur.status AS usage_status,
                    COALESCE(charge.created_at, ur.created_at) AS occurred_at,
                    COALESCE(charge.id, before.id, 0) AS seq,
                    charge.amount_nanos AS amount_nanos,
                    ur.max_charge_nanos AS max_charge_nanos,
                    COALESCE(charge.balance_after, before.balance_after, 0) AS balance_after,
                    COALESCE(t.id, s.base_take_id)::text AS take_id,
                    COALESCE(t.project_id, s.project_id)::text AS project_id,
                    COALESCE(t.prompt, s.instruction) AS detail,
                    NULL::text AS invoice_id,
                    NULL::text AS invoice_number
                FROM usage_records ur
                LEFT JOIN ledger charge ON charge.usage_record_id = ur.id AND charge.type = 'charge'
                LEFT JOIN LATERAL (
                    SELECT l.id, l.balance_after
                    FROM ledger l
                    WHERE l.created_at <= ur.created_at
                    ORDER BY l.created_at DESC, l.id DESC
                    LIMIT 1
                ) before ON charge.id IS NULL
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
                    l.id,
                    l.amount_nanos,
                    NULL,
                    l.balance_after,
                    NULL,
                    NULL,
                    NULL,
                    i.id::text,
                    i.number
                FROM ledger l
                LEFT JOIN invoices i ON i.purchase_id = l.purchase_id
                WHERE l.type <> 'charge'
            )
            SELECT kind, row_key, usage_status, occurred_at, amount_nanos, max_charge_nanos, balance_after,
                   take_id, project_id, detail, invoice_id, invoice_number
            FROM activity
            ORDER BY occurred_at DESC, seq DESC, row_key DESC
            LIMIT ? OFFSET ?
            SQL, [
            $customer->id,
            $take,
            $take,
            $suggestion,
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
