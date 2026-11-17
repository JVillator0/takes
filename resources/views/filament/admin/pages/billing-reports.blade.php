{{-- Billing › Reports (A03, normative). Money is formatted once, from exact nanodollars (D-042). --}}
@php
    use App\DTOs\Billing\BillingReportFilterDTO;
    use App\Filament\Admin\Support\Money;

    $report = $this->report;
    $byDay = $this->filter()->groupBy === BillingReportFilterDTO::BY_DAY;
    $total = $report->totals;
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('console.reports.from') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="from" />
            </x-filament::input.wrapper>
        </label>
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('console.reports.until') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="until" />
            </x-filament::input.wrapper>
        </label>

        <x-filament::tabs>
            <x-filament::tabs.item :active="$groupBy === 'day'" wire:click="$set('groupBy', 'day')">
                {{ __('console.reports.by_day') }}
            </x-filament::tabs.item>
            <x-filament::tabs.item :active="$groupBy === 'customer'" wire:click="$set('groupBy', 'customer')">
                {{ __('console.reports.by_customer') }}
            </x-filament::tabs.item>
        </x-filament::tabs>

        <x-filament::tabs>
            <x-filament::tabs.item :active="$provider === ''" wire:click="$set('provider', '')">
                {{ __('console.reports.all_providers') }}
            </x-filament::tabs.item>
            @foreach (\App\Enums\Billing\Provider::cases() as $case)
                <x-filament::tabs.item :active="$provider === $case->value" wire:click="$set('provider', '{{ $case->value }}')">
                    {{ __('console.reports.providers.'.$case->value) }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('console.common.customer') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="customerId">
                    <option value="">{{ __('console.reports.all_customers') }}</option>
                    @foreach ($this->customers as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    <x-filament::section>
        @if ($report->rows === [])
            <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400" data-testid="reports-empty">
                {{ __('console.reports.empty') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="reports-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-2 py-2 text-start">{{ $byDay ? __('console.reports.day') : __('console.common.customer') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.takes') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.failed') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.generations') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.suggestions') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.spend') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.pixelforge_cost') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.anthropic_cost') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.profit') }}</th>
                            <th class="px-2 py-2 text-end">{{ __('console.reports.margin') }}</th>
                            @unless ($byDay)
                                <th class="px-2 py-2 text-end">{{ __('console.reports.share') }}</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report->rows as $row)
                            @php($margin = $row->marginBps())
                            <tr class="border-b border-gray-100 dark:border-white/5" wire:key="row-{{ $row->key }}">
                                <td class="px-2 py-2">{{ $row->label }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ number_format($row->takes) }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ number_format($row->failedTakes) }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ number_format($row->generationsCharged) }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ number_format($row->suggestions) }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ $row->spend->formatExact() }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ $row->pixelforgeCost->formatExact() }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ $row->anthropicCost->formatExact() }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ $row->profit()->formatExact() }}</td>
                                <td @class(['px-2 py-2 text-end tabular-nums', 'font-semibold text-danger-600 dark:text-danger-400' => $margin !== null && $margin !== 2000])>{{ Money::percentFromBps($margin) }}</td>
                                @unless ($byDay)
                                    <td class="px-2 py-2 text-end tabular-nums">{{ Money::percentFromBps($row->shareOfSpendBps($total->spend)) }}</td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20" data-testid="reports-total">
                            <td class="px-2 py-2">{{ __('console.reports.total') }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ number_format($total->takes) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ number_format($total->failedTakes) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ number_format($total->generationsCharged) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ number_format($total->suggestions) }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ $total->spend->formatExact() }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ $total->pixelforgeCost->formatExact() }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ $total->anthropicCost->formatExact() }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ $total->profit()->formatExact() }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ Money::percentFromBps($total->marginBps()) }}</td>
                            @unless ($byDay)
                                <td class="px-2 py-2 text-end tabular-nums">{{ $total->spend->isZero() ? '-' : '100.0%' }}</td>
                            @endunless
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('console.reports.footnote') }}</p>
    </x-filament::section>
</x-filament-panels::page>
