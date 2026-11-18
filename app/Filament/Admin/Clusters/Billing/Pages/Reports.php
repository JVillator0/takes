<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Billing\Pages;

use App\DTOs\Billing\BillingReportDTO;
use App\DTOs\Billing\BillingReportFilterDTO;
use App\DTOs\Billing\BillingReportRowDTO;
use App\Enums\Auth\AdminPermission;
use App\Enums\Billing\Provider;
use App\Filament\Admin\Clusters\Billing\BillingCluster;
use App\Filament\Admin\Clusters\Billing\Widgets\BillingReportStats;
use App\Filament\Admin\Clusters\Providers\Pages\PixelforgeUsage;
use App\Filament\Admin\Support\Money;
use App\Models\Customer;
use App\Models\User;
use App\Queries\Billing\GetBillingReportQuery;
use App\Queries\Billing\ListReconciledDaysQuery;
use App\Support\Money\Nanodollars;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Billing › Reports (A03, normative; D-035): customer spend vs provider cost vs profit, by UTC day or by customer,
 * with a customer and a provider filter, default last 30 days. Native Filament only (#163): the filters are a
 * schema form, the totals a stats widget, the rows a Filament table. The margin column doubles as the invariant
 * check: 25% markup on cost is 20.0% (2000 bps) of spend, "-" when nothing was spent. Reads only the database.
 *
 * `$data` is the form state; `$filters` is the last **valid** filter (Locked), the one source of truth for the
 * table, the stats and the export. An invalid date edit shows a field error and leaves `$filters` unchanged.
 *
 * @property-read BillingReportDTO $report  memoized per request
 * @property-read list<string> $reconciledDays
 * @property-read Schema $form
 */
final class Reports extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    /** Customer options returned per search; the search reaches every customer. */
    public const int CUSTOMER_OPTIONS = 50;

    private const string ALL_PROVIDERS = 'all';

    protected static ?string $cluster = BillingCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{from: string, until: string, group_by: string, customer_id: int|null, provider: string|null} */
    #[Locked]
    public array $filters = ['from' => '', 'until' => '', 'group_by' => BillingReportFilterDTO::BY_DAY, 'customer_id' => null, 'provider' => null];

    public function mount(): void
    {
        $default = BillingReportFilterDTO::lastThirtyDays();
        $this->filters = [
            'from' => $default->from->toDateString(),
            'until' => $default->until->toDateString(),
            'group_by' => BillingReportFilterDTO::BY_DAY,
            'customer_id' => null,
            'provider' => null,
        ];
        $this->form->fill([
            'from' => $this->filters['from'],
            'until' => $this->filters['until'],
            'group_by' => BillingReportFilterDTO::BY_DAY,
            'provider' => self::ALL_PROVIDERS,
            'customer' => null,
        ]);
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
     * The filters, one column on mobile. From md: From and Until share a row, Group by fills its own row, and
     * Provider and Customer share the next. From xl: From, Until and Group by fill one row of four, and Provider and
     * Customer split the next, so each toggle group gets two of four columns and no button runs under its
     * neighbour at 1280–1440 px (#176).
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        DatePicker::make('from')->label(__('console.reports.from'))->live()
                            ->afterStateUpdated(fn () => $this->applyFilters('from')),
                        DatePicker::make('until')->label(__('console.reports.until'))->live()
                            ->afterStateUpdated(fn () => $this->applyFilters('until')),
                        ToggleButtons::make('group_by')->label(__('console.reports.group_by'))->inline()->grouped()->live()
                            ->columnSpan(['default' => 1, 'md' => 2])
                            ->options([
                                BillingReportFilterDTO::BY_DAY => __('console.reports.by_day'),
                                BillingReportFilterDTO::BY_CUSTOMER => __('console.reports.by_customer'),
                            ])
                            ->afterStateUpdated(fn () => $this->applyFilters('group_by')),
                        ToggleButtons::make('provider')->label(__('console.reports.provider'))->inline()->grouped()->live()
                            ->columnSpan(['default' => 1, 'md' => 1, 'xl' => 2])
                            ->options([
                                self::ALL_PROVIDERS => __('console.reports.all_providers'),
                                ...collect(Provider::cases())->mapWithKeys(fn (Provider $provider): array => [$provider->value => __('console.reports.providers.'.$provider->value)])->all(),
                            ])
                            ->afterStateUpdated(fn () => $this->applyFilters('provider')),
                        Select::make('customer')->label(__('console.common.customer'))->live()
                            ->columnSpan(['default' => 1, 'md' => 1, 'xl' => 2])
                            ->placeholder(__('console.reports.all_customers'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::searchCustomers($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? self::customerName((int) $value) : null)
                            ->afterStateUpdated(fn () => $this->applyFilters('customer')),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Livewire::make(BillingReportStats::class, fn (): array => BillingReportStats::props($this->report->totals))->key('billing-report-stats'),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Customers whose name or email contains the search, at most CUSTOMER_OPTIONS of them.
     *
     * @return array<int, string>
     */
    public static function searchCustomers(string $search): array
    {
        $search = trim($search);

        /** @var array<int, string> */
        return Customer::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereLike('name', '%'.$search.'%')
                ->orWhereLike('email', '%'.$search.'%')))
            ->orderBy('name')
            ->limit(self::CUSTOMER_OPTIONS)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Applies the form to `$filters`. From/Until apply only when both are real dates, ordered and within MAX_DAYS
     * (like Pixelforge usage); a cleared or invalid date is a field error, never "today". Unknown grouping, provider
     * or customer values fall back to the defaults, so every control matches what the table shows.
     */
    public function applyFilters(string $field): void
    {
        $state = $this->data ?? [];
        $groupBy = in_array($state['group_by'] ?? null, [BillingReportFilterDTO::BY_DAY, BillingReportFilterDTO::BY_CUSTOMER], true) ? $state['group_by'] : BillingReportFilterDTO::BY_DAY;
        $provider = is_string($state['provider'] ?? null) ? Provider::tryFrom($state['provider']) : null;
        $customer = $state['customer'] ?? null;
        $customerId = is_int($customer) || (is_string($customer) && ctype_digit($customer)) ? (int) $customer : null;

        $this->data['group_by'] = $groupBy;
        $this->data['provider'] = $provider->value ?? self::ALL_PROVIDERS;
        $this->data['customer'] = $customerId;

        $filters = [...$this->filters, 'group_by' => $groupBy, 'customer_id' => $customerId, 'provider' => $provider?->value];

        $this->resetErrorBag(['data.from', 'data.until']);
        $dates = ['from' => self::date($state['from'] ?? null), 'until' => self::date($state['until'] ?? null)];

        foreach (array_keys(array_filter($dates, fn (?string $date): bool => $date === null)) as $invalid) {
            $this->addError('data.'.$invalid, __('console.reports.invalid_date'));
        }

        if ($dates['from'] !== null && $dates['until'] !== null) {
            $from = CarbonImmutable::parse($dates['from'], 'UTC');
            $until = CarbonImmutable::parse($dates['until'], 'UTC');

            if ($until->lessThan($from) || $from->diffInDays($until) >= BillingReportFilterDTO::MAX_DAYS) {
                $this->addError('data.'.(in_array($field, ['from', 'until'], true) ? $field : 'from'), __('console.reports.invalid_range', ['days' => BillingReportFilterDTO::MAX_DAYS]));
            } else {
                $filters = [...$filters, 'from' => $dates['from'], 'until' => $dates['until']];
            }
        }

        if ($filters !== $this->filters) {
            $this->filters = $filters;
            unset($this->report, $this->reconciledDays);
            $this->resetPage($this->getTablePaginationPageName());
            $this->flushCachedTableRecords();
        }
    }

    /**
     * The one source of truth for the table, the stats and the export.
     */
    public function filter(): BillingReportFilterDTO
    {
        return BillingReportFilterDTO::fromArray($this->filters);
    }

    #[Computed]
    public function report(): BillingReportDTO
    {
        return app(GetBillingReportQuery::class)->execute($this->filter());
    }

    /**
     * By-day rows matched by the last cached Pixelforge usage check (#141); no provider call.
     *
     * @return list<string>
     */
    #[Computed]
    public function reconciledDays(): array
    {
        $filter = $this->filter();

        return $filter->groupBy === BillingReportFilterDTO::BY_DAY
            ? app(ListReconciledDaysQuery::class)->execute($filter->from, $filter->until)
            : [];
    }

    public function table(Table $table): Table
    {
        $byDay = fn (): bool => $this->filters['group_by'] === BillingReportFilterDTO::BY_DAY;
        $total = fn (): BillingReportRowDTO => $this->report->totals;

        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginatedRows($page, $recordsPerPage))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->heading(fn (): string => $byDay() ? __('console.reports.by_day') : __('console.reports.by_customer'))
            ->description(__('console.reports.footnote'))
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading(__('console.reports.empty'))
            ->columns([
                TextColumn::make('label')
                    ->label(fn (): string => $byDay() ? __('console.reports.day') : __('console.common.customer')),
                TextColumn::make('reconciled')
                    ->label(__('console.reports.pixelforge_check'))
                    ->badge()
                    ->color('success')
                    ->state(fn (array $record): mixed => $record['reconciled'] ? __('console.reports.reconciled') : null)
                    ->url(fn (array $record): ?string => $record['reconciled'] ? PixelforgeUsage::getUrl(['from' => $record[ArrayRecord::getKeyName()], 'until' => $record[ArrayRecord::getKeyName()]]) : null)
                    ->visible($byDay),
                self::count('takes', 'console.reports.takes', fn (): int => $total()->takes),
                self::count('failed_takes', 'console.reports.failed', fn (): int => $total()->failedTakes),
                self::count('generations_charged', 'console.reports.generations', fn (): int => $total()->generationsCharged),
                self::count('suggestions', 'console.reports.suggestions', fn (): int => $total()->suggestions),
                self::money('spend_nanos', 'console.reports.spend', fn (): Nanodollars => $total()->spend),
                self::money('cost_nanos', 'console.reports.provider_cost', fn (): Nanodollars => $total()->cost),
                self::money('pixelforge_cost_nanos', 'console.reports.pixelforge_cost', fn (): Nanodollars => $total()->pixelforgeCost),
                self::money('anthropic_cost_nanos', 'console.reports.anthropic_cost', fn (): Nanodollars => $total()->anthropicCost),
                self::money('profit_nanos', 'console.reports.profit', fn (): Nanodollars => $total()->profit()),
                self::money('promo_consumed_nanos', 'console.reports.promo_consumed', fn (): Nanodollars => $total()->promoConsumed),
                TextColumn::make('margin_bps')
                    ->label(__('console.reports.margin'))
                    ->alignment(Alignment::End)
                    ->fontFamily('mono')
                    ->state(fn (array $record): string => Money::percentFromBps($record['margin_bps']))
                    ->color(fn (array $record): ?string => $record['breaks_margin'] ? 'danger' : null)
                    ->weight(fn (array $record): ?string => $record['breaks_margin'] ? 'bold' : null)
                    ->summarize(Summarizer::make('total')->label(__('console.reports.total'))
                        ->using(fn (): string => Money::percentFromBps($total()->marginBps()))
                        ->extraAttributes(fn (): array => $total()->breaksMarginInvariant() ? ['class' => 'text-danger-600 dark:text-danger-400', 'data-invariant' => 'broken'] : [])),
                TextColumn::make('share_bps')
                    ->label(__('console.reports.share'))
                    ->alignment(Alignment::End)
                    ->fontFamily('mono')
                    ->state(fn (array $record): string => Money::percentFromBps($record['share_bps']))
                    ->visible(fn (): bool => ! $byDay())
                    ->summarize(Summarizer::make('total')->label(__('console.reports.total'))
                        ->using(fn (): string => $total()->spend->isZero() ? '-' : '100.0%')),
            ]);
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
     * The rows of the current report as table records (keyed by day or customer id), one page of them.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatedRows(int $page, int $perPage): LengthAwarePaginator
    {
        $report = $this->report;
        $reconciled = array_flip($this->reconciledDays);
        $rows = array_map(fn (BillingReportRowDTO $row): array => [
            ArrayRecord::getKeyName() => $row->key,
            'label' => $row->label,
            'reconciled' => isset($reconciled[$row->key]),
            'takes' => $row->takes,
            'failed_takes' => $row->failedTakes,
            'generations_charged' => $row->generationsCharged,
            'suggestions' => $row->suggestions,
            'spend_nanos' => $row->spend->toInt(),
            'cost_nanos' => $row->cost->toInt(),
            'pixelforge_cost_nanos' => $row->pixelforgeCost->toInt(),
            'anthropic_cost_nanos' => $row->anthropicCost->toInt(),
            'profit_nanos' => $row->profit()->toInt(),
            'promo_consumed_nanos' => $row->promoConsumed->toInt(),
            'margin_bps' => $row->marginBps(),
            'breaks_margin' => $row->breaksMarginInvariant(),
            'share_bps' => $row->shareOfSpendBps($report->totals->spend),
        ], $report->rows);

        $page = max(1, $page);

        /** @var LengthAwarePaginator<int, array<string, mixed>> */
        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
    }

    /**
     * @param  Closure(): int  $total
     */
    private static function count(string $name, string $label, Closure $total): TextColumn
    {
        return TextColumn::make($name)
            ->label(__($label))
            ->numeric()
            ->alignment(Alignment::End)
            ->summarize(Summarizer::make('total')->label(__('console.reports.total'))->using(fn (): string => number_format($total())));
    }

    /**
     * @param  Closure(): Nanodollars  $total
     */
    private static function money(string $name, string $label, Closure $total): TextColumn
    {
        return TextColumn::make($name)
            ->label(__($label))
            ->alignment(Alignment::End)
            ->fontFamily('mono')
            ->formatStateUsing(fn (int $state): string => Nanodollars::fromInt($state)->formatExact())
            ->summarize(Summarizer::make('total')->label(__('console.reports.total'))->using(fn (): string => $total()->formatExact()));
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

    /** A real Y-m-d date (DatePicker state), or null for a cleared or malformed one. */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? substr($value, 0, 10) : null;
    }

    private static function customerName(int $id): ?string
    {
        $name = Customer::query()->whereKey($id)->value('name');

        return is_string($name) ? $name : null;
    }

    private static function admin(): ?User
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User ? $admin : null;
    }
}
