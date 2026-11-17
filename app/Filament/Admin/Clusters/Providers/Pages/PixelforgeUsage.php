<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Providers\Pages;

use App\DTOs\Billing\PixelforgeReconciliationDTO;
use App\DTOs\Billing\ReconciliationGenerationDTO;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Filament\Admin\Clusters\Providers\ProvidersCluster;
use App\Queries\Billing\FindPixelforgeTakesQuery;
use App\Queries\Billing\GetPixelforgeReconciliationQuery;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Providers › Pixelforge usage (A04, normative; D-034, D-058): the brief's "admin report matches GET /v1/usage".
 * Per UTC day, our settled usage against Pixelforge's, with "N / M days match", Pending ("N in flight") for today
 * and days with open takes, and **Find takes** on a mismatching day. Read-only, so the auditor sees it too.
 */
final class PixelforgeUsage extends Page
{
    use HasPageShield;

    public const int DEFAULT_DAYS = 14;

    protected static ?string $cluster = ProvidersCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $slug = 'pixelforge-usage';

    protected string $view = 'filament.admin.pages.pixelforge-usage';

    public string $from = '';

    public string $until = '';

    public bool $refresh = false;

    /** @var array{date: string, truncated: bool, groups: array<string, list<array{generation: string, take: string|null, theirs: string, ours: string}>>}|null */
    public ?array $findResult = null;

    public function mount(): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $this->from = $today->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        $this->until = $today->toDateString();
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
        $query = app(GetPixelforgeReconciliationQuery::class);
        $refresh = $this->refresh;
        $this->refresh = false;

        try {
            return $query->execute(CarbonImmutable::parse($this->from, 'UTC'), CarbonImmutable::parse($this->until, 'UTC'), $refresh);
        } catch (InvalidArgumentException) {
            $this->mount();

            return $query->execute(CarbonImmutable::parse($this->from, 'UTC'), CarbonImmutable::parse($this->until, 'UTC'), $refresh);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'until'], true)) {
            $this->findResult = null;
        }
    }

    /**
     * **Find takes** on one mismatching day: pages Pixelforge's generations (explicit click only).
     */
    public function findTakes(string $date): void
    {
        try {
            $result = app(FindPixelforgeTakesQuery::class)->execute(CarbonImmutable::parse($date, 'UTC'));
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('console.pixelforge.refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $this->refresh = true;
                    unset($this->report);
                }),
        ];
    }
}
