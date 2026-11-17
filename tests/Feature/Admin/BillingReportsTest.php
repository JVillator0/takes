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
use Spatie\Permission\PermissionRegistrar;

// Spec 7 issue 3 / A03 (D-035): totals equal Σ settled usage in every grouping, margin 2000 bps on every non-empty
// day and "-" on an empty one, exact CSV with formula-injection protection.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
});

/**
 * The CSV the export action writes, parsed with the writer's own escape setting.
 *
 * @return list<list<string>>
 */
function exportLines(User $admin, BillingReportFilterDTO $filter): array
{
    $stream = fopen('php://memory', 'w+b');
    app(ExportBillingReportCsvAction::class)->execute($admin, $filter, $stream);
    rewind($stream);
    $csv = (string) stream_get_contents($stream);

    return array_values(array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", $csv))));
}

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
        // Bob's row: one settled Pixelforge usage of 47,560,000 in the range (the September one is outside it).
        ->and([$byCustomer->rows[1]->key, $byCustomer->rows[1]->spend->toInt(), $byCustomer->rows[1]->generationsCharged])
        ->toBe([(string) $bob->id, 59_450_000, 1]);
});

it('renders the report for the auditor with the margin check and the dash on an empty day', function (): void {
    reportDataset();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(Reports::class)
        ->assertOk()
        ->assertSee('20.0%')
        ->assertSee('$0.0740375')
        ->assertSeeHtml('data-testid="margin-2026-11-15">-</td>')
        ->assertSeeHtml('data-testid="margin-2026-11-16">20.0%</td>')
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

    $lines = exportLines($operator, BillingReportFilterDTO::lastThirtyDays());
    $anaId = (string) Customer::query()->where('name', 'Ana López')->value('id');

    expect($lines[0])->toBe(ExportBillingReportCsvAction::COLUMNS)
        // Generations (charged) count Pixelforge only: Ana's Anthropic row exports 0, and the column sums to the screen total.
        ->and(collect($lines)->first(fn (array $line): bool => $line[1] === $anaId && $line[3] === 'anthropic')[4] ?? null)->toBe('0')
        ->and(array_sum(array_map(fn (array $line): int => (int) $line[4], array_slice($lines, 1))))
        ->toBe(app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays())->totals->generationsCharged)
        ->and(count($lines))->toBe(5)
        ->and($lines[1])->toBe(['2026-11-16', (string) Customer::query()->where('name', 'Ana López')->value('id'), 'Ana López', 'pixelforge', '1', '0.074037500', '0.059230000', '0.014807500', '2000', '74037500', '59230000', '14807500'])
        ->and(collect($lines)->firstWhere(2, "'=HYPERLINK(\"http://evil\")"))->not->toBeNull()
        ->and(array_sum(array_map(fn (array $line): int => (int) $line[9], array_slice($lines, 1))))
        ->toBe(app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays())->totals->spend->toInt())
        ->and(Activity::query()->where('event', 'exported')->where('causer_id', $operator->id)->exists())->toBeTrue();

    $filtered = exportLines($operator, BillingReportFilterDTO::fromArray(['from' => '2026-10-19', 'until' => '2026-11-17', 'customer_id' => (int) $anaId, 'provider' => 'pixelforge']));
    $audit = Activity::query()->where('event', 'exported')->latest('id')->first();

    expect(array_unique(array_map(fn (array $line): string => $line[1].'/'.$line[3], array_slice($filtered, 1))))->toBe([$anaId.'/pixelforge'])
        ->and([$audit?->properties['customer_id'] ?? null, $audit?->properties['provider'] ?? null])->toBe([(int) $anaId, 'pixelforge']);

    $this->actingAs($operator);
    Livewire::test(Reports::class)
        ->assertActionVisible('exportCsv')
        ->assertActionHasUrl('exportCsv', route('filament.admin.billing.reports.export', [
            'from' => '2026-10-19', 'until' => '2026-11-17', 'group_by' => 'day',
        ]));
});

it('keeps the last valid range and reports a lang error for an invalid one, never reading a cleared date as today', function (): void {
    reportDataset();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(Reports::class)
        ->set('from', '')
        ->assertHasErrors(['from'])
        ->assertSet('rangeFrom', '2026-10-19')
        ->set('from', '2025-01-01')
        ->assertHasErrors(['from'])
        ->set('from', '2026-11-20')
        ->assertHasErrors(['from'])
        ->assertSee('2026-11-16')
        ->set('from', '2026-11-16')
        ->assertHasNoErrors()
        ->assertSet('rangeFrom', '2026-11-16');
});

it('lets the operator pick a customer and go back to all customers', function (): void {
    ['ana' => $ana, 'bob' => $bob] = reportDataset();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(Reports::class)
        ->set('groupBy', 'customer')
        ->set('customer', (string) $ana->id)
        ->assertSeeHtml('wire:key="row-'.$ana->id.'"')
        ->assertDontSeeHtml('wire:key="row-'.$bob->id.'"')
        ->set('customer', '')
        ->assertOk()
        ->assertSeeHtml('wire:key="row-'.$bob->id.'"');
});

it('flags a total margin other than 20.0%', function (): void {
    $customer = Customer::factory()->create();
    UsageRecord::factory()->settled(100_000_000)->create(['customer_id' => $customer->id, 'billing_date' => '2026-11-16', 'markup_bps' => 3000, 'charge_nanos' => 130_000_000]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    // markup 3000 bps on cost ⇒ margin 3000 / 13000 of spend = 2307 bps.
    Livewire::test(Reports::class)->assertSeeHtml('text-danger-600 dark:text-danger-400" data-testid="margin-total">23.1%</td>');
});

it('rejects an unordered range, a range over the maximum and an unknown grouping', function (array $data): void {
    expect(fn () => BillingReportFilterDTO::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
    'until before from' => [['from' => '2026-11-17', 'until' => '2026-11-16']],
    '367 inclusive days' => [['from' => '2025-11-16', 'until' => '2026-11-17']],
    'unknown grouping' => [['from' => '2026-11-16', 'until' => '2026-11-17', 'group_by' => 'week']],
]);

it('hides Billing in the sidebar from an admin who may open nothing in it', function (): void {
    $admin = User::factory()->admin(AdminRole::Auditor)->create();
    $admin->roles()->first()?->revokePermissionTo('View:Reports');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin->refresh())->get('/admin')->assertOk()->assertDontSee('Billing');
});

it('streams the CSV from a real GET route for an admin with Export:Reports, and refuses everyone else', function (): void {
    reportDataset();
    $query = ['from' => '2026-10-19', 'until' => '2026-11-17', 'group_by' => 'day', 'provider' => 'pixelforge'];
    $url = route('filament.admin.billing.reports.export', $query);

    $response = $this->actingAs(User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create())->get($url);
    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertDownload('takes-report-2026-10-19-2026-11-17.csv');
    $lines = array_values(array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", $response->streamedContent()))));

    expect($lines[0])->toBe(ExportBillingReportCsvAction::COLUMNS)
        ->and(array_unique(array_map(fn (array $line): string => $line[3], array_slice($lines, 1))))->toBe(['pixelforge']);

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())->get($url)->assertForbidden();
    $this->actingAs(User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create())
        ->get(route('filament.admin.billing.reports.export', ['from' => '2026-11-17', 'until' => '2026-11-01']))
        ->assertSessionHasErrors('until');
});

it('prefixes a name starting with a tab', function (): void {
    $tabbed = Customer::factory()->create(['name' => "\tcmd"]);
    UsageRecord::factory()->settled(59_230_000)->create(['customer_id' => $tabbed->id, 'billing_date' => '2026-11-16']);

    $lines = exportLines(User::factory()->admin(AdminRole::Operator)->create(), BillingReportFilterDTO::lastThirtyDays());

    expect($lines[1][2])->toBe("'\tcmd");
});

it('opens the Reports page over HTTP for the auditor', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())->get(Reports::getUrl())->assertOk();
});

it('includes the first and last day of the range and nothing outside it', function (): void {
    $customer = Customer::factory()->create();
    foreach (['2026-10-18' => 10_000_000, '2026-10-19' => 20_000_000, '2026-11-17' => 40_000_000] as $day => $cost) {
        UsageRecord::factory()->settled($cost)->create(['customer_id' => $customer->id, 'billing_date' => $day]);
    }
    Take::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-11-17 23:59:59']);
    Take::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-11-18 00:00:00']);
    Take::factory()->create(['customer_id' => $customer->id, 'created_at' => '2026-10-18 23:59:59']);

    $totals = app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays())->totals;

    // The UsageRecord factory also creates one take per usage, created now (2026-11-17 12:00).
    expect($totals->cost->toInt())->toBe(60_000_000)
        ->and($totals->takes)->toBe(Take::query()->where('created_at', '>=', '2026-10-19 00:00:00')->where('created_at', '<', '2026-11-18 00:00:00')->count())
        ->and($totals->takes)->toBe(4);
});

it('renders Export CSV as a real download link, never an SPA navigation', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create());
    $html = $this->get(Reports::getUrl())->assertOk()->getContent();

    preg_match('/<a[^>]*billing\/reports\/export[^>]*>/', (string) $html, $link);

    expect($link[0] ?? '')->toContain('download')
        ->not->toContain('wire:navigate');
});
