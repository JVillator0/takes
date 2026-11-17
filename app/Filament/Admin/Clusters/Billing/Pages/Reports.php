<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Billing\Pages;

use App\Actions\Billing\ExportBillingReportCsvAction;
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
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public ?int $customerId = null;

    public function mount(): void
    {
        $default = BillingReportFilterDTO::lastThirtyDays();
        $this->from = $default->from->toDateString();
        $this->until = $default->until->toDateString();
    }

    public function getTitle(): string
    {
        return __('console.reports.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('console.reports.title');
    }

    public function filter(): BillingReportFilterDTO
    {
        try {
            return BillingReportFilterDTO::fromArray([
                'from' => $this->from,
                'until' => $this->until,
                'group_by' => $this->groupBy,
                'customer_id' => $this->customerId,
                'provider' => Provider::tryFrom($this->provider)?->value,
            ]);
        } catch (InvalidArgumentException) {
            return BillingReportFilterDTO::lastThirtyDays();
        }
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
                ->action(function (): ?StreamedResponse {
                    $admin = self::admin();

                    return $admin === null ? null : app(ExportBillingReportCsvAction::class)->execute($admin, $this->filter());
                }),
        ];
    }

    private static function admin(): ?User
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User ? $admin : null;
    }
}
