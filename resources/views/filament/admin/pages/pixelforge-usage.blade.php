{{-- Providers › Pixelforge usage (A04, normative): our settled usage vs GET /v1/usage, 9 decimals (D-034). --}}
@php
    use App\Enums\Billing\ReconciliationStatus;

    $report = $this->report;
    $fields = ['input_text_tokens' => 'text', 'input_image_tokens' => 'image', 'output_tokens' => 'output', 'reasoning_tokens' => 'reasoning'];
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('console.pixelforge.from') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="from" />
            </x-filament::input.wrapper>
        </label>
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('console.pixelforge.until') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="until" />
            </x-filament::input.wrapper>
        </label>
    </div>

    @if ($report->unavailable)
        <div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300" data-testid="pixelforge-unavailable">
            {{ $report->fetchedAt ? __('console.pixelforge.unavailable', ['time' => $report->fetchedAt->utc()->format('Y-m-d H:i')]) : __('console.pixelforge.unavailable_never') }}
        </div>
    @elseif ($report->fetchedAt)
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('console.pixelforge.fetched', ['time' => $report->fetchedAt->utc()->format('Y-m-d H:i')]) }}</p>
    @endif

    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="pixelforge-usage-table">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="px-2 py-2 text-start">{{ __('console.pixelforge.day') }}</th>
                        <th class="px-2 py-2 text-end">{{ __('console.pixelforge.generations') }}</th>
                        <th class="px-2 py-2 text-end">{{ __('console.pixelforge.cost') }}</th>
                        <th class="px-2 py-2 text-start">{{ __('console.pixelforge.tokens') }}</th>
                        <th class="px-2 py-2 text-start">{{ __('console.pixelforge.status_column') }}</th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->days as $day)
                        @php($differences = $day->theirs ? array_intersect_key($day->ours->differences($day->theirs), $fields) : [])
                        <tr class="border-b border-gray-100 dark:border-white/5" wire:key="day-{{ $day->date }}">
                            <td class="px-2 py-2">{{ $day->date }}</td>
                            <td class="px-2 py-2 text-end tabular-nums">{{ $day->ours->generations }} / {{ $day->theirs?->generations ?? '-' }}</td>
                            <td class="px-2 py-2 text-end font-mono tabular-nums">{{ $day->ours->cost->toDecimalString() }} / {{ $day->theirs?->cost->toDecimalString() ?? '-' }}</td>
                            <td class="px-2 py-2 font-mono text-xs">
                                @foreach ($differences as $field => $difference)
                                    <span>{{ $fields[$field] }} {{ $difference > 0 ? '+' : '' }}{{ number_format($difference) }}</span>
                                @endforeach
                            </td>
                            <td class="px-2 py-2">
                                <x-filament::badge :color="$day->status->getColor()">
                                    {{ $day->status === ReconciliationStatus::Pending && $day->inFlight > 0 ? __('console.pixelforge.in_flight', ['count' => $day->inFlight]) : $day->status->getLabel() }}
                                </x-filament::badge>
                            </td>
                            <td class="px-2 py-2 text-end">
                                @if ($day->status === ReconciliationStatus::Mismatch)
                                    <x-filament::button size="xs" color="gray" wire:click="findTakes('{{ $day->date }}')">
                                        {{ __('console.pixelforge.find_takes') }}
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20" data-testid="pixelforge-usage-total">
                        <td class="px-2 py-2">{{ __('console.pixelforge.total') }}</td>
                        <td class="px-2 py-2 text-end tabular-nums">{{ $report->oursTotal->generations }} / {{ $report->theirsTotal?->generations ?? '-' }}</td>
                        <td class="px-2 py-2 text-end font-mono tabular-nums">{{ $report->oursTotal->cost->toDecimalString() }} / {{ $report->theirsTotal?->cost->toDecimalString() ?? '-' }}</td>
                        <td class="px-2 py-2" colspan="3">{{ __('console.pixelforge.summary', ['matched' => $report->matchedDays, 'judged' => $report->judgedDays]) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    @if ($findResult)
        <x-filament::section :heading="__('console.pixelforge.find_heading', ['date' => $findResult['date']])" data-testid="find-takes">
            @if ($findResult['truncated'])
                <p class="mb-3 text-sm text-warning-700 dark:text-warning-300">{{ __('console.pixelforge.truncated') }}</p>
            @endif

            @php($empty = collect($findResult['groups'])->every(fn (array $rows): bool => $rows === []))
            @if ($empty)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('console.pixelforge.nothing') }}</p>
            @endif

            @foreach ($findResult['groups'] as $group => $rows)
                @if ($rows !== [])
                    <h3 class="mt-4 text-sm font-semibold">{{ __('console.pixelforge.groups.'.$group) }} ({{ count($rows) }})</h3>
                    <table class="mt-2 w-full text-sm">
                        <thead>
                            <tr class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                <th class="px-2 py-1 text-start">{{ __('console.pixelforge.generation') }}</th>
                                <th class="px-2 py-1 text-start">{{ __('console.pixelforge.take') }}</th>
                                <th class="px-2 py-1 text-end">{{ __('console.pixelforge.their_cost') }}</th>
                                <th class="px-2 py-1 text-end">{{ __('console.pixelforge.our_cost') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="px-2 py-1 font-mono text-xs">{{ $row['generation'] }}</td>
                                    <td class="px-2 py-1 font-mono text-xs">{{ $row['take'] ?? '-' }}</td>
                                    <td class="px-2 py-1 text-end font-mono">{{ $row['theirs'] }}</td>
                                    <td class="px-2 py-1 text-end font-mono">{{ $row['ours'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
