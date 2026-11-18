<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Concerns;

use Carbon\CarbonImmutable;

/**
 * The dashboard charts' range select (the ECharts widget's `$filter`): the last 14 (default, also when no option is
 * chosen yet) or 30 UTC days, today included. Any other value (a tampered Livewire property) falls back to 14.
 */
trait HasDashboardRange
{
    public const string DEFAULT_RANGE = '14';

    /** @var list<string> */
    public const array RANGES = ['14', '30'];

    /** Livewire trait hook: the select shows the default range from the first render. */
    public function mountHasDashboardRange(): void
    {
        $this->filter ??= self::DEFAULT_RANGE;
    }

    /**
     * @return array<int|string, string> the day count (a numeric key) => its label
     */
    protected function getFilters(): array
    {
        return array_combine(self::RANGES, array_map(fn (string $days): string => (string) __('console.dashboard.range.'.$days), self::RANGES));
    }

    protected function rangeDays(): int
    {
        return (int) (in_array($this->filter, self::RANGES, true) ? $this->filter : self::DEFAULT_RANGE);
    }

    protected function rangeFrom(): CarbonImmutable
    {
        return $this->rangeUntil()->subDays($this->rangeDays() - 1);
    }

    protected function rangeUntil(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay();
    }
}
