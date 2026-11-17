<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Pages;

use App\DTOs\Billing\PixelforgeReconciliationDTO;
use App\DTOs\Billing\ReconciliationGenerationDTO;
use App\Enums\Billing\ReconciliationStatus;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Filament\Admin\Clusters\Providers\ProvidersCluster;
use App\Queries\Billing\GetPixelforgeReconciliationQuery;
use App\Queries\Billing\ListPixelforgeDayDiscrepanciesQuery;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Providers › Pixelforge usage (A04, normative; D-034, D-058): the brief's "admin report matches GET /v1/usage".
 * Per UTC day, our settled usage against Pixelforge's, with "N of M closed days match", Pending ("N in flight")
 * for today and days with open takes, and **Find takes** on a mismatching day. Read-only, so the auditor sees it.
 *
 * The report reads the last valid range (`rangeFrom`/`rangeUntil`); a date input that would make the range
 * invalid shows an error and changes nothing, so no intermediate range ever calls Pixelforge.
 *
 * @property-read PixelforgeReconciliationDTO $report  the #[Computed] report, memoized per request
 */
final class PixelforgeUsage extends Page
{
    use HasPageShield;

    public const int DEFAULT_DAYS = 14;

    public const string PROVIDER_CHECKS_KEY = 'admin:pixelforge-checks';

    public const int PROVIDER_CHECKS_PER_MINUTE = 6;

    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    protected static ?string $cluster = ProvidersCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $slug = 'pixelforge-usage';

    protected string $view = 'filament.admin.pages.pixelforge-usage';

    public string $from = '';

    public string $until = '';

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
        $this->from = $this->rangeFrom = $today->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        $this->until = $this->rangeUntil = $today->toDateString();
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
     * Applies From/Until only when both are real dates, ordered and at most 92 days apart (inclusive).
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['from', 'until'], true)) {
            return;
        }

        $this->resetErrorBag(['from', 'until']);
        $invalid = array_filter(['from' => $this->from, 'until' => $this->until], fn (string $value): bool => ! self::isDate($value));

        foreach (array_keys($invalid) as $field) {
            $this->addError($field, __('console.pixelforge.invalid_date'));
        }

        if ($invalid !== []) {
            return;
        }

        $from = CarbonImmutable::parse($this->from, 'UTC');
        $until = CarbonImmutable::parse($this->until, 'UTC');

        if ($until->lessThan($from) || $from->diffInDays($until) >= GetPixelforgeReconciliationQuery::MAX_DAYS) {
            $this->addError($property, __('console.pixelforge.invalid_range', ['days' => GetPixelforgeReconciliationQuery::MAX_DAYS]));

            return;
        }

        $this->rangeFrom = $this->from;
        $this->rangeUntil = $this->until;
        $this->findResult = null;
        unset($this->report);
    }

    /**
     * **Find takes** (A04, D-034): only for a Mismatch day of the current report, read on an explicit click.
     */
    public function findTakesAction(): Action
    {
        return Action::make('findTakes')
            ->label(__('console.pixelforge.find_takes'))
            ->size('xs')
            ->color('gray')
            ->authorize(fn (): bool => self::canAccess())
            ->action(function (array $arguments): void {
                $date = $arguments['date'] ?? null;

                if (! is_string($date) || ! $this->isMismatchDay($date)) {
                    Notification::make()->danger()->title(__('console.pixelforge.not_a_mismatch'))->send();

                    return;
                }

                if (! Cache::has(ListPixelforgeDayDiscrepanciesQuery::cacheKey($date)) && ! self::spendProviderCheck()) {
                    return;
                }

                try {
                    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse($date, 'UTC'));
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
                    if (! self::spendProviderCheck()) {
                        return;
                    }

                    $this->refreshUsage = true;
                    $this->findResult = null;
                    unset($this->report);
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

    /**
     * Find takes (uncached) and Refresh call Pixelforge on the account-wide 60 rpm budget the customers' takes
     * share, so every admin together gets a few per minute (the auditor's login is public).
     */
    private static function spendProviderCheck(): bool
    {
        if (RateLimiter::attempt(self::PROVIDER_CHECKS_KEY, self::PROVIDER_CHECKS_PER_MINUTE, fn (): bool => true)) {
            return true;
        }

        Notification::make()->warning()
            ->title(__('console.pixelforge.throttled', ['seconds' => RateLimiter::availableIn(self::PROVIDER_CHECKS_KEY)]))
            ->send();

        return false;
    }

    private static function isDate(string $value): bool
    {
        if (preg_match(self::DATE_PATTERN, $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year);
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
