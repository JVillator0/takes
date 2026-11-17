<?php

declare(strict_types=1);

use App\Actions\Billing\ExportBillingReportCsvAction;
use App\DTOs\Billing\BillingReportFilterDTO;
use App\Enums\Auth\AdminRole;
use App\Enums\Billing\Provider;
use App\Filament\Admin\Clusters\Billing\Pages\Reports;
use App\Models\Customer;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Queries\Billing\GetBillingReportQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// Spec 7 issue 3 / A03 (D-035): totals equal Σ settled usage in every grouping, margin 2000 bps on every non-empty
// day and "-" on an empty one, exact CSV with formula-injection protection.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
});

/**
 * Two customers over two days; voided, open and out-of-range usage must not count.
 *
 * @return array{ana: Customer, bob: Customer}
 */
function reportDataset(): array
{
    $ana = Customer::factory()->create(['name' => 'Ana López']);
    $bob = Customer::factory()->create(['name' => '=HYPERLINK("http://evil")']);

    $settle = function (Customer $customer, string $day, int $cost, Provider $provider = Provider::Pixelforge): void {
        $factory = UsageRecord::factory()->settled($cost);
        $factory = $provider === Provider::Anthropic ? $factory->forSuggestion() : $factory;
        $factory->create(['customer_id' => $customer->id, 'billing_date' => $day]);
    };

    $settle($ana, '2026-11-16', 59_230_000);
    $settle($ana, '2026-11-17', 86_180_000);
    $settle($ana, '2026-11-17', 254_000, Provider::Anthropic);
    $settle($bob, '2026-11-17', 47_560_000);
    $settle($bob, '2026-09-01', 99_000_000); // out of the 30-day range

    UsageRecord::factory()->voided()->create(['customer_id' => $ana->id]);
    UsageRecord::factory()->create(['customer_id' => $bob->id]); // open hold

    Take::factory()->failed()->create(['customer_id' => $bob->id, 'created_at' => '2026-11-15 10:00:00']);

    return ['ana' => $ana, 'bob' => $bob];
}

it('totals equal the settled usage by day, with a 20.0% margin on every day that has spend', function (): void {
    reportDataset();

    $report = app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays());
    $expectedCost = 59_230_000 + 86_180_000 + 254_000 + 47_560_000;
    $expectedSpend = (int) (($expectedCost * 125) / 100);

    expect(array_map(fn ($row): string => $row->key, $report->rows))->toBe(['2026-11-17', '2026-11-16', '2026-11-15'])
        ->and($report->totals->cost->toInt())->toBe($expectedCost)
        ->and($report->totals->spend->toInt())->toBe($expectedSpend)
        ->and(array_sum(array_map(fn ($row): int => $row->spend->toInt(), $report->rows)))->toBe($expectedSpend)
        ->and($report->totals->generationsCharged)->toBe(3)
        ->and($report->totals->suggestions)->toBe(1)
        ->and($report->totals->failedTakes)->toBe(1)
        ->and($report->rows[0]->marginBps())->toBe(2000)
        ->and($report->rows[1]->marginBps())->toBe(2000)
        ->and($report->rows[2]->marginBps())->toBeNull()
        ->and($report->totals->marginBps())->toBe(2000);
});

it('totals equal the settled usage by customer and filter by customer and provider', function (): void {
    ['ana' => $ana, 'bob' => $bob] = reportDataset();
    $query = app(GetBillingReportQuery::class);

    $byCustomer = $query->execute(BillingReportFilterDTO::fromArray(['from' => '2026-10-19', 'until' => '2026-11-17', 'group_by' => 'customer']));
    $anaOnly = $query->execute(BillingReportFilterDTO::fromArray(['from' => '2026-10-19', 'until' => '2026-11-17', 'customer_id' => $ana->id]));
    $anthropic = $query->execute(BillingReportFilterDTO::fromArray(['from' => '2026-10-19', 'until' => '2026-11-17', 'provider' => 'anthropic']));

    expect(array_map(fn ($row): string => $row->label, $byCustomer->rows))->toBe(['Ana López', '=HYPERLINK("http://evil")'])
        ->and(array_sum(array_map(fn ($row): int => $row->spend->toInt(), $byCustomer->rows)))->toBe($byCustomer->totals->spend->toInt())
        ->and($byCustomer->rows[0]->spend->toInt())->toBe(182_080_000)
        ->and($anaOnly->totals->spend->toInt())->toBe(182_080_000)
        ->and($anaOnly->totals->takes)->toBe(Take::query()->where('customer_id', $ana->id)->count())
        ->and($anthropic->totals->spend->toInt())->toBe(317_500)
        ->and($anthropic->totals->takes)->toBe(0)
        ->and($bob->id)->toBeInt();
});

it('renders the report for the auditor with the margin check and the dash on an empty day', function (): void {
    reportDataset();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(Reports::class)
        ->assertOk()
        ->assertSee('20.0%')
        ->assertSee('$0.0740375')
        ->assertSeeInOrder(['2026-11-15', '-'])
        ->assertActionHidden('exportCsv')
        ->set('groupBy', 'customer')
        ->assertSee('Ana López')
        ->set('from', '2026-01-01')
        ->set('until', '2026-01-31')
        ->assertSee('No settled usage in this range');

    Http::assertNothingSent();
});

it('is hidden from an admin without the Reports page permission', function (): void {
    $admin = User::factory()->admin(AdminRole::Auditor)->create();
    $admin->roles()->first()?->revokePermissionTo('View:Reports');

    $this->actingAs($admin)->get(Reports::getUrl())->assertForbidden();
});

it('exports day × customer × provider rows with exact decimals, a formula guard and an audit entry', function (): void {
    reportDataset();
    $operator = User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create();

    $response = app(ExportBillingReportCsvAction::class)->execute($operator, BillingReportFilterDTO::lastThirtyDays());
    ob_start();
    $response->sendContent();
    $lines = array_map(str_getcsv(...), array_filter(explode("\n", (string) ob_get_clean())));

    expect($lines[0])->toBe(ExportBillingReportCsvAction::COLUMNS)
        ->and(count($lines))->toBe(5)
        ->and($lines[1])->toBe(['2026-11-16', (string) Customer::query()->where('name', 'Ana López')->value('id'), 'Ana López', 'pixelforge', '1', '0.074037500', '0.059230000', '0.014807500', '2000', '74037500', '59230000', '14807500'])
        ->and(collect($lines)->firstWhere(2, "'=HYPERLINK(\"http://evil\")"))->not->toBeNull()
        ->and(array_sum(array_map(fn (array $line): int => (int) $line[9], array_slice($lines, 1))))
        ->toBe(app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays())->totals->spend->toInt())
        ->and(Activity::query()->where('event', 'exported')->where('causer_id', $operator->id)->exists())->toBeTrue();

    $this->actingAs($operator);
    Livewire::test(Reports::class)->assertActionVisible('exportCsv')->callAction('exportCsv')->assertFileDownloaded('takes-report-2026-10-19-2026-11-17.csv');
});
