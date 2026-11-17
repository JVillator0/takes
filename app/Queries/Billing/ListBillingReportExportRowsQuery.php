<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\BillingReportFilterDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Models\UsageRecord;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;

/**
 * The Reports CSV rows (spec 7, D-035): settled usage always grouped by day × customer × provider, whatever the
 * on-screen grouping, with the same customer and provider filters. Streamed with a cursor.
 */
final readonly class ListBillingReportExportRowsQuery
{
    /**
     * @return LazyCollection<int, object{billing_date: string, customer_id: int, customer_name: string, provider: string, generations_charged: int, spend_nanos: int|string, cost_nanos: int|string}>
     */
    public function execute(BillingReportFilterDTO $filter): LazyCollection
    {
        /** @var LazyCollection<int, object{billing_date: string, customer_id: int, customer_name: string, provider: string, generations_charged: int, spend_nanos: int|string, cost_nanos: int|string}> */
        return UsageRecord::query()
            ->toBase()
            ->join('customers', 'customers.id', '=', 'usage_records.customer_id')
            ->where('usage_records.status', UsageStatus::Settled->value)
            ->whereBetween('usage_records.billing_date', [$filter->from->toDateString(), $filter->until->toDateString()])
            ->when($filter->customerId !== null, fn (Builder $query): Builder => $query->where('usage_records.customer_id', $filter->customerId))
            ->when($filter->provider !== null, fn (Builder $query): Builder => $query->where('usage_records.provider', $filter->provider?->value))
            ->selectRaw('usage_records.billing_date::text as billing_date')
            ->selectRaw('usage_records.customer_id as customer_id')
            ->selectRaw('customers.name as customer_name')
            ->selectRaw('usage_records.provider as provider')
            // Generations (charged) are settled Pixelforge usages only (spec 7 Definitions): an Anthropic row has 0.
            ->selectRaw('count(*) filter (where usage_records.provider = ?) as generations_charged', [Provider::Pixelforge->value])
            ->selectRaw('sum(usage_records.charge_nanos) as spend_nanos')
            ->selectRaw('sum(usage_records.provider_cost_nanos) as cost_nanos')
            ->groupBy('usage_records.billing_date', 'usage_records.customer_id', 'customers.name', 'usage_records.provider')
            ->orderBy('usage_records.billing_date')
            ->orderBy('usage_records.customer_id')
            ->orderBy('usage_records.provider')
            ->cursor();
    }
}
