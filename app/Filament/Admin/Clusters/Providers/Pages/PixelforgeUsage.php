<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Pages;

use App\DTOs\Billing\PixelforgeReconciliationDTO;
use App\DTOs\Billing\ReconciliationDayDTO;
use App\DTOs\Billing\ReconciliationGenerationDTO;
use App\Enums\Billing\ReconciliationStatus;
use App\Exceptions\Pixelforge\AdminRequestBudgetExhaustedException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Filament\Admin\Clusters\Providers\ProvidersCluster;
use App\Filament\Admin\Clusters\Providers\Widgets\PixelforgeDiscrepancies;
use App\Queries\Billing\GetPixelforgeReconciliationQuery;
use App\Queries\Billing\ListPixelforgeDayDiscrepanciesQuery;
use App\Support\Pixelforge\AdminRequestBudget;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
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
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Providers › Pixelforge usage (A04, normative; D-034, D-058): the brief's "admin report matches GET /v1/usage".
 * Per UTC day, our settled usage against Pixelforge's, with "N of M closed days match", Pending ("N in flight")
 * for today and days with open takes, and **Find takes** on a mismatching day. Read-only, so the auditor sees it.
 *
 * The report reads the last valid range (`rangeFrom`/`rangeUntil`); a date input that would make the range
 * invalid shows an error and changes nothing. A valid intermediate range (while typing) may fetch uncached days,
 * and every such call is charged to the admin request budget (AdminRequestBudget).
 *
 * Native Filament only (#163): the dates are a schema form, the days a Filament table (one row per day, totals as
 * summaries), Find takes a row action, and its result a nested table widget (PixelforgeDiscrepancies).
 *
 * @property-read PixelforgeReconciliationDTO $report  the #[Computed] report, memoized per request
 * @property-read Schema $form
 */
final class PixelforgeUsage extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    public const int DEFAULT_DAYS = 14;

    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    protected static ?string $cluster = ProvidersCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $slug = 'pixelforge-usage';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public string $rangeFrom = '';

    #[Locked]
    public string $rangeUntil = '';

    /** @var array{date: string, truncated: bool, groups: array<string, list<array{generation: string, take: string|null, theirs: string, ours: string}>>}|null */
    #[Locked]
    public ?array $findResult = null;

    /** Set only by the Refresh action within one request; never client state. */
    private bool $refreshUsage = false;

    public function mount(): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $this->rangeFrom = $today->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        $this->rangeUntil = $today->toDateString();

        // Optional ?from=&until= (e.g. the Reports reconciled badge links to its day): used only when both are real
        // dates, ordered and within MAX_DAYS; anything else keeps the default window.
        $from = self::date(request()->query('from'));
        $until = self::date(request()->query('until'));

        if ($from !== null && $until !== null && $from <= $until
            && CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($until, 'UTC')) < GetPixelforgeReconciliationQuery::MAX_DAYS) {
            $this->rangeFrom = $from;
            $this->rangeUntil = $until;
        }

        $this->form->fill(['from' => $this->rangeFrom, 'until' => $this->rangeUntil]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->columns(['md' => 2])
                    ->schema([
                        DatePicker::make('from')->label(__('console.pixelforge.from'))->live()
                            ->afterStateUpdated(fn () => $this->applyRange('from')),
                        DatePicker::make('until')->label(__('console.pixelforge.until'))->live()
                            ->afterStateUpdated(fn () => $this->applyRange('until')),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Callout::make(__('console.pixelforge.throttled_banner'))
                ->warning()
                ->visible(fn (): bool => $this->report->throttled),
            Callout::make(fn (): string => $this->unavailableMessage())
                ->warning()
                ->visible(fn (): bool => $this->report->unavailable),
            EmbeddedTable::make(),
            Livewire::make(PixelforgeDiscrepancies::class, fn (): array => ['findResult' => $this->findResult])
                ->key('pixelforge-discrepancies')
                ->visible(fn (): bool => $this->findResult !== null),
        ]);
    }

    public function table(Table $table): Table
    {
        $report = fn (): PixelforgeReconciliationDTO => $this->report;

        return $table
            ->records(fn (): array => $this->dayRecords())
            ->paginated(false)
            ->heading(fn (): string => __('console.pixelforge.summary', ['matched' => $report()->matchedDays, 'judged' => $report()->judgedDays]))
            ->description(fn (): string => trim(($report()->fetchedAt && ! $report()->unavailable ? __('console.pixelforge.fetched', ['time' => $report()->fetchedAt->utc()->format('Y-m-d H:i')]).' ' : '').__('console.pixelforge.footnote')))
            ->columns([
                TextColumn::make('day')->label(__('console.pixelforge.day')),
                TextColumn::make('generations')->label(__('console.pixelforge.generations'))->alignment(Alignment::End)
                    ->summarize(Summarizer::make('total')->label(__('console.pixelforge.total'))
                        ->using(fn (): string => $report()->oursTotal->generations.' / '.($report()->theirsTotal->generations ?? '-'))),
                TextColumn::make('ours')->label(__('console.pixelforge.our_cost_column'))->alignment(Alignment::End)->fontFamily('mono')
                    ->summarize(Summarizer::make('total')->label(__('console.pixelforge.total'))
                        ->using(fn (): string => $report()->oursTotal->cost->toDecimalString())),
                TextColumn::make('theirs')->label(__('console.pixelforge.their_cost_column'))->alignment(Alignment::End)->fontFamily('mono')
                    ->summarize(Summarizer::make('total')->label(__('console.pixelforge.total'))
                        ->using(fn (): string => $report()->theirsTotal?->cost->toDecimalString() ?? '-')),
                TextColumn::make('difference')->label(__('console.pixelforge.difference'))->alignment(Alignment::End)->fontFamily('mono')
                    ->color(fn (array $record): ?string => $record['differs'] ? 'danger' : null)
                    ->summarize(Summarizer::make('total')->label(__('console.pixelforge.total'))
                        ->using(fn (): string => $report()->theirsTotal ? $report()->oursTotal->cost->minus($report()->theirsTotal->cost)->toDecimalString() : '-')),
                TextColumn::make('tokens')->label(__('console.pixelforge.tokens'))->fontFamily('mono')->listWithLineBreaks()->placeholder(''),
                TextColumn::make('status')->label(__('console.pixelforge.status_column'))->badge()
                    ->color(fn (array $record): string => $record['status_color']),
            ])
            ->recordActions([
                $this->findTakesAction(),
            ]);
    }

    /**
     * One table record per day of the report, keyed by its date.
     *
     * @return list<array<string, mixed>>
     */
    private function dayRecords(): array
    {
        $fields = (array) __('console.pixelforge.token_kinds');
        $today = CarbonImmutable::now('UTC')->toDateString();

        return array_map(function (ReconciliationDayDTO $day) use ($fields, $today): array {
            $difference = $day->theirs !== null ? $day->ours->cost->minus($day->theirs->cost) : null;
            $tokens = [];

            foreach ($day->theirs !== null ? array_intersect_key($day->ours->differences($day->theirs), $fields) : [] as $field => $delta) {
                $tokens[] = $fields[$field].' '.($delta > 0 ? '+' : '').number_format($delta);
            }

            return [
                ArrayRecord::getKeyName() => $day->date,
                'date' => $day->date,
                'day' => $day->date === $today ? __('console.pixelforge.today', ['date' => $day->date]) : $day->date,
                'generations' => $day->ours->generations.' / '.($day->theirs->generations ?? '-'),
                'ours' => $day->ours->cost->toDecimalString(),
                'theirs' => $day->theirs?->cost->toDecimalString() ?? '-',
                'difference' => $difference?->toDecimalString() ?? '-',
                'differs' => $difference !== null && ! $difference->isZero(),
                'tokens' => $tokens,
                'status' => $day->status === ReconciliationStatus::Pending && $day->inFlight > 0
                    ? __('console.pixelforge.in_flight', ['count' => $day->inFlight])
                    : $day->status->getLabel(),
                'status_value' => $day->status->value,
                'status_color' => $day->status->getColor(),
            ];
        }, $this->report->days);
    }

    private function unavailableMessage(): string
    {
        $fetchedAt = $this->report->fetchedAt?->utc();

        return $fetchedAt !== null
            ? __('console.pixelforge.unavailable', ['time' => $fetchedAt->format($fetchedAt->isToday() ? 'H:i' : 'Y-m-d H:i')])
            : __('console.pixelforge.unavailable_never');
    }

    public function getTitle(): string
    {
        return __('console.pixelforge.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('console.pixelforge.title');
    }

    #[Computed]
    public function report(): PixelforgeReconciliationDTO
    {
        return app(GetPixelforgeReconciliationQuery::class)->execute(
            CarbonImmutable::parse($this->rangeFrom, 'UTC'),
            CarbonImmutable::parse($this->rangeUntil, 'UTC'),
            $this->refreshUsage,
        );
    }

    /**
     * Applies From/Until only when both are real dates, ordered and at most 92 days apart (inclusive); otherwise a
     * field error and the report stays on the last valid range.
     */
    public function applyRange(string $field): void
    {
        $this->resetErrorBag(['data.from', 'data.until']);
        $dates = ['from' => self::date($this->data['from'] ?? null), 'until' => self::date($this->data['until'] ?? null)];
        $invalid = array_keys(array_filter($dates, fn (?string $date): bool => $date === null));

        foreach ($invalid as $name) {
            $this->addError('data.'.$name, __('console.pixelforge.invalid_date'));
        }

        if ($dates['from'] === null || $dates['until'] === null) {
            return;
        }

        $from = CarbonImmutable::parse($dates['from'], 'UTC');
        $until = CarbonImmutable::parse($dates['until'], 'UTC');

        if ($until->lessThan($from) || $from->diffInDays($until) >= GetPixelforgeReconciliationQuery::MAX_DAYS) {
            $this->addError('data.'.$field, __('console.pixelforge.invalid_range', ['days' => GetPixelforgeReconciliationQuery::MAX_DAYS]));

            return;
        }

        $this->rangeFrom = $dates['from'];
        $this->rangeUntil = $dates['until'];
        $this->findResult = null;
        unset($this->report);
        $this->flushCachedTableRecords();
    }

    /**
     * **Find takes** (A04, D-034): a row action, only on a Mismatch day of the current report, read on an explicit
     * click; the result shows in the nested drill-down table.
     */
    private function findTakesAction(): Action
    {
        return Action::make('findTakes')
            ->label(__('console.pixelforge.find_takes'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('gray')
            ->authorize(fn (): bool => self::canAccess())
            ->visible(fn (array $record): bool => $record['status_value'] === ReconciliationStatus::Mismatch->value)
            ->action(function (array $record): void {
                $date = $record['date'] ?? null;

                if (! is_string($date) || ! $this->isMismatchDay($date)) {
                    Notification::make()->danger()->title(__('console.pixelforge.not_a_mismatch'))->send();

                    return;
                }

                try {
                    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse($date, 'UTC'));
                } catch (AdminRequestBudgetExhaustedException) {
                    self::notifyThrottled();

                    return;
                } catch (PixelforgeException|PixelforgeNotConfigured) {
                    Notification::make()->danger()->title(__('console.pixelforge.unavailable_short'))->send();

                    return;
                }

                $this->findResult = [
                    'date' => $result->date,
                    'truncated' => $result->truncated,
                    'groups' => [
                        'theirs_not_ours' => self::rows($result->theirsNotOurs),
                        'ours_not_settled' => self::rows($result->oursNotSettled),
                        'amount_differs' => self::rows($result->amountDiffers),
                        'orphan_success' => self::rows($result->orphanSuccess),
                    ],
                ];
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('console.pixelforge.refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    // Refresh spends one request of the admin budget (taken when the report refetches).
                    if (AdminRequestBudget::remaining() < 1) {
                        self::notifyThrottled();

                        return;
                    }

                    $this->refreshUsage = true;
                    $this->findResult = null;
                    unset($this->report);
                    $this->flushCachedTableRecords();
                }),
        ];
    }

    private function isMismatchDay(string $date): bool
    {
        if (! self::isDate($date) || $date < $this->rangeFrom || $date > $this->rangeUntil) {
            return false;
        }

        foreach ($this->report->days as $day) {
            if ($day->date === $date) {
                return $day->status === ReconciliationStatus::Mismatch;
            }
        }

        return false;
    }

    private static function notifyThrottled(): void
    {
        Notification::make()->warning()
            ->title(__('console.pixelforge.throttled', ['seconds' => AdminRequestBudget::availableIn()]))
            ->send();
    }

    private static function isDate(string $value): bool
    {
        return self::date($value) === $value;
    }

    /** A real Y-m-d date (DatePicker state), or null for a cleared or malformed one. */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 32 || preg_match(self::DATE_PATTERN, substr($value, 0, 10)) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', substr($value, 0, 10)));

        return checkdate($month, $day, $year) ? substr($value, 0, 10) : null;
    }

    /**
     * @param  list<ReconciliationGenerationDTO>  $generations
     * @return list<array{generation: string, take: string|null, theirs: string, ours: string}>
     */
    private static function rows(array $generations): array
    {
        return array_map(fn (ReconciliationGenerationDTO $row): array => [
            'generation' => $row->generationId,
            'take' => $row->takeId,
            'theirs' => $row->theirCost?->toDecimalString() ?? '-',
            'ours' => $row->ourCost?->toDecimalString() ?? '-',
        ], $generations);
    }
}
