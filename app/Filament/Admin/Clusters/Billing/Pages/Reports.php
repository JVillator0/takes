<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Billing\Pages;

use App\DTOs\Billing\BillingReportDTO;
use App\DTOs\Billing\BillingReportFilterDTO;
use App\Enums\Auth\AdminPermission;
use App\Enums\Billing\Provider;
use App\Filament\Admin\Clusters\Billing\BillingCluster;
use App\Models\Customer;
use App\Models\User;
use App\Queries\Billing\GetBillingReportQuery;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Billing › Reports (A03, normative; D-035): customer spend vs provider cost vs profit, by UTC day or by customer,
 * with a customer and a provider filter, default last 30 days. The margin column doubles as the invariant check:
 * 25% markup on cost is 20.0% (2000 bps) of spend, "-" when nothing was spent. Reads only the database.
 */
final class Reports extends Page
{
    use HasPageShield;

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected string $view = 'filament.admin.pages.billing-reports';

    public string $from = '';

    public string $until = '';

    public string $groupBy = BillingReportFilterDTO::BY_DAY;

    public string $provider = '';

    /** The selected customer id as the select sends it ('' for all customers); converted in filter(). */
    public string $customer = '';

    /** The last valid range: an invalid date edit shows an error and leaves the report on this range. */
    #[Locked]
    public string $rangeFrom = '';

    #[Locked]
    public string $rangeUntil = '';

    public function mount(): void
    {
        $default = BillingReportFilterDTO::lastThirtyDays();
        $this->from = $this->rangeFrom = $default->from->toDateString();
        $this->until = $this->rangeUntil = $default->until->toDateString();
    }

    public function getTitle(): string
    {
        return __('console.reports.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('console.reports.title');
    }

    /**
     * Applies From/Until only when both are real dates, ordered and within MAX_DAYS (like Pixelforge usage); a
     * cleared or invalid date is an error, never "today". Unknown grouping or provider values fall back to the
     * defaults, so every control always matches what the table shows.
     */
    public function updated(string $property): void
    {
        if (! in_array($this->groupBy, [BillingReportFilterDTO::BY_DAY, BillingReportFilterDTO::BY_CUSTOMER], true)) {
            $this->groupBy = BillingReportFilterDTO::BY_DAY;
        }

        if ($this->provider !== '' && Provider::tryFrom($this->provider) === null) {
            $this->provider = '';
        }

        if ($this->customer !== '' && ! ctype_digit($this->customer)) {
            $this->customer = '';
        }

        if (! in_array($property, ['from', 'until'], true)) {
            return;
        }

        $this->resetErrorBag(['from', 'until']);
        $invalid = array_filter(['from' => $this->from, 'until' => $this->until], fn (string $value): bool => ! self::isDate($value));

        foreach (array_keys($invalid) as $field) {
            $this->addError($field, __('console.reports.invalid_date'));
        }

        if ($invalid !== []) {
            return;
        }

        $from = CarbonImmutable::parse($this->from, 'UTC');
        $until = CarbonImmutable::parse($this->until, 'UTC');

        if ($until->lessThan($from) || $from->diffInDays($until) >= BillingReportFilterDTO::MAX_DAYS) {
            $this->addError($property, __('console.reports.invalid_range', ['days' => BillingReportFilterDTO::MAX_DAYS]));

            return;
        }

        $this->rangeFrom = $this->from;
        $this->rangeUntil = $this->until;
        unset($this->report);
    }

    /**
     * The one source of truth for the table, the controls and the export.
     */
    public function filter(): BillingReportFilterDTO
    {
        return BillingReportFilterDTO::fromArray([
            'from' => $this->rangeFrom,
            'until' => $this->rangeUntil,
            'group_by' => in_array($this->groupBy, [BillingReportFilterDTO::BY_DAY, BillingReportFilterDTO::BY_CUSTOMER], true) ? $this->groupBy : BillingReportFilterDTO::BY_DAY,
            'customer_id' => ctype_digit($this->customer) ? (int) $this->customer : null,
            'provider' => Provider::tryFrom($this->provider)?->value,
        ]);
    }

    #[Computed]
    public function report(): BillingReportDTO
    {
        return app(GetBillingReportQuery::class)->execute($this->filter());
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function customers(): array
    {
        /** @var array<int, string> */
        return Customer::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label(__('console.reports.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize(fn (): bool => self::admin()?->can(AdminPermission::ExportReports->value) === true)
                // A real GET route streams the file; the filters travel in the query string and are validated there.
                ->url(fn (): string => route('filament.admin.billing.reports.export', $this->exportQuery()))
                ->openUrlInNewTab(false)
                // Under spa() a link is a wire:navigate visit unless excluded: the panel lists the export route in
                // spaUrlExceptions, and `download` tells the browser to save the response.
                ->extraAttributes(['download' => true]),
        ];
    }

    /**
     * @return array{from: string, until: string, group_by: string, customer_id: int|null, provider: string|null}
     */
    private function exportQuery(): array
    {
        $filter = $this->filter();

        return [
            'from' => $filter->from->toDateString(),
            'until' => $filter->until->toDateString(),
            'group_by' => $filter->groupBy,
            'customer_id' => $filter->customerId,
            'provider' => $filter->provider?->value,
        ];
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year);
    }

    private static function admin(): ?User
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User ? $admin : null;
    }
}
