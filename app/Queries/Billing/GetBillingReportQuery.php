<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\BillingReportDTO;
use App\DTOs\Billing\BillingReportFilterDTO;
use App\DTOs\Billing\BillingReportRowDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\Customer;
use App\Models\Take;
use App\Models\UsageRecord;
use Illuminate\Database\Query\Builder;

/**
 * Billing › Reports (A03, D-035), straight from the spec 7 Definitions: one `GROUP BY` over settled usage records
 * by `billing_date` or customer (spend, cost, cost per provider, charged generations and suggestions), joined in
 * PHP with the takes created in the same UTC days (all statuses, and the failed ones). Purchases are never spend.
 * Days or customers with no settled usage and no take are omitted. The totals row is its own aggregate.
 */
final readonly class GetBillingReportQuery
{
    public function execute(BillingReportFilterDTO $filter): BillingReportDTO
    {
        $byDay = $filter->groupBy === BillingReportFilterDTO::BY_DAY;
        $usageKey = $byDay ? 'billing_date::text' : 'customer_id::text';
        $takeKey = $byDay ? "(created_at at time zone 'UTC')::date::text" : 'customer_id::text';

        $usage = $this->usage($filter)
            ->selectRaw("{$usageKey} as key")
            ->groupByRaw($usageKey)
            ->get()
            ->keyBy('key');

        $takes = $this->takes($filter)
            ->selectRaw("{$takeKey} as key")
            ->groupByRaw($takeKey)
            ->get()
            ->keyBy('key');

        $keys = $usage->keys()->merge($takes->keys())->unique()->map(fn (mixed $key): string => (string) $key);
        $names = $byDay ? collect() : Customer::query()->whereIn('id', $keys->all())->pluck('name', 'id');

        $rows = array_values($keys
            ->map(fn (string $key): BillingReportRowDTO => $this->row(
                $key,
                $byDay ? $key : (string) ($names[(int) $key] ?? $key),
                $usage->get($key),
                $takes->get($key),
            ))
            ->sort(fn (BillingReportRowDTO $a, BillingReportRowDTO $b): int => $byDay
                ? strcmp($b->key, $a->key)
                : (($b->spend->toInt() <=> $a->spend->toInt()) ?: strcmp($a->label, $b->label)))
            ->all());

        return new BillingReportDTO($rows, $this->row('total', 'Total', $this->usage($filter)->first(), $this->takes($filter)->first()));
    }

    /**
     * Settled usage in the range, aggregated (add a key to group).
     */
    private function usage(BillingReportFilterDTO $filter): Builder
    {
        return UsageRecord::query()
            ->toBase()
            ->where('status', UsageStatus::Settled->value)
            ->whereBetween('billing_date', [$filter->from->toDateString(), $filter->until->toDateString()])
            ->when($filter->customerId !== null, fn (Builder $query): Builder => $query->where('customer_id', $filter->customerId))
            ->when($filter->provider !== null, fn (Builder $query): Builder => $query->where('provider', $filter->provider?->value))
            ->selectRaw('coalesce(sum(charge_nanos), 0) as spend_nanos')
            ->selectRaw('coalesce(sum(provider_cost_nanos), 0) as cost_nanos')
            ->selectRaw('coalesce(sum(provider_cost_nanos) filter (where provider = ?), 0) as pixelforge_cost_nanos', [Provider::Pixelforge->value])
            ->selectRaw('coalesce(sum(provider_cost_nanos) filter (where provider = ?), 0) as anthropic_cost_nanos', [Provider::Anthropic->value])
            ->selectRaw('count(*) filter (where provider = ?) as generations_charged', [Provider::Pixelforge->value])
            ->selectRaw('count(*) filter (where provider = ?) as suggestions', [Provider::Anthropic->value]);
    }

    /**
     * Takes created in the range (UTC days), all statuses, and the failed ones. Takes are Pixelforge work: an
     * Anthropic-only report counts none.
     */
    private function takes(BillingReportFilterDTO $filter): Builder
    {
        return Take::query()
            ->toBase()
            ->where('created_at', '>=', $filter->from)
            ->where('created_at', '<', $filter->until->addDay())
            ->when($filter->customerId !== null, fn (Builder $query): Builder => $query->where('customer_id', $filter->customerId))
            ->when($filter->provider === Provider::Anthropic, fn (Builder $query): Builder => $query->whereRaw('false'))
            ->selectRaw('count(*) as takes')
            ->selectRaw('count(*) filter (where status = ?) as failed_takes', [TakeStatus::Failed->value]);
    }

    private function row(string $key, string $label, ?object $usage, ?object $takes): BillingReportRowDTO
    {
        return BillingReportRowDTO::fromArray([
            'key' => $key,
            'label' => $label,
            'spend_nanos' => $usage->spend_nanos ?? 0,
            'cost_nanos' => $usage->cost_nanos ?? 0,
            'pixelforge_cost_nanos' => $usage->pixelforge_cost_nanos ?? 0,
            'anthropic_cost_nanos' => $usage->anthropic_cost_nanos ?? 0,
            'generations_charged' => $usage->generations_charged ?? 0,
            'suggestions' => $usage->suggestions ?? 0,
            'takes' => (int) ($takes->takes ?? 0),
            'failed_takes' => (int) ($takes->failed_takes ?? 0),
        ]);
    }
}
