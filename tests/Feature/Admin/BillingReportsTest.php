<?php

declare(strict_types=1);

use App\Actions\Billing\ExportBillingReportCsvAction;
use App\DTOs\Billing\BillingReportFilterDTO;
use App\Enums\Auth\AdminRole;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Filament\Admin\Clusters\Billing\Pages\Reports;
use App\Filament\Admin\Clusters\Billing\Widgets\BillingReportStats;
use App\Filament\Admin\Clusters\Providers\Pages\PixelforgeUsage;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Queries\Billing\GetBillingReportQuery;
use App\Queries\Billing\GetPixelforgeReconciliationQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
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
    ['ana' => $ana] = reportDataset();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(Reports::class)
        ->assertOk()
        ->assertCanSeeTableRecords(['2026-11-17', '2026-11-16', '2026-11-15'])
        ->assertTableColumnStateSet('margin_bps', '-', '2026-11-15')
        ->assertTableColumnStateSet('margin_bps', '20.0%', '2026-11-16')
        ->assertTableColumnFormattedStateSet('spend_nanos', '$0.0740375', '2026-11-16')
        ->assertTableColumnHidden('share_bps')
        ->assertActionHidden('exportCsv')
        ->fillForm(['group_by' => 'customer'])
        ->assertTableColumnStateSet('label', 'Ana López', (string) $ana->id)
        ->assertTableColumnVisible('share_bps')
        ->fillForm(['from' => '2026-01-01', 'until' => '2026-01-31'])
        ->assertCountTableRecords(0)
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
        ->and($lines[1])->toBe(['2026-11-16', (string) Customer::query()->where('name', 'Ana López')->value('id'), 'Ana López', 'pixelforge', '1', '0.074037500', '0.059230000', '0.014807500', '2000', '74037500', '59230000', '14807500', '0.000000000', '0'])
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
        ->fillForm(['from' => null])
        ->assertHasFormErrors(['from'])
        ->assertSet('filters.from', '2026-10-19')
        ->fillForm(['from' => '2025-01-01'])
        ->assertHasFormErrors(['from'])
        ->fillForm(['from' => '2026-11-20'])
        ->assertHasFormErrors(['from'])
        ->assertCanSeeTableRecords(['2026-11-16'])
        ->fillForm(['from' => '2026-11-16'])
        ->assertHasNoFormErrors()
        ->assertSet('filters.from', '2026-11-16')
        ->assertCanNotSeeTableRecords(['2026-11-15']);
});

it('lets the operator pick a customer and go back to all customers', function (): void {
    ['ana' => $ana, 'bob' => $bob] = reportDataset();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(Reports::class)
        ->fillForm(['group_by' => 'customer', 'customer' => $ana->id])
        ->assertCanSeeTableRecords([(string) $ana->id])
        ->assertCanNotSeeTableRecords([(string) $bob->id])
        ->assertSet('filters.customer_id', $ana->id)
        ->fillForm(['customer' => null])
        ->assertOk()
        ->assertCanSeeTableRecords([(string) $ana->id, (string) $bob->id]);
});

it('flags a total margin other than 20.0%', function (): void {
    $customer = Customer::factory()->create();
    UsageRecord::factory()->settled(100_000_000)->create(['customer_id' => $customer->id, 'billing_date' => '2026-11-16', 'markup_bps' => 3000, 'charge_nanos' => 130_000_000]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    // markup 3000 bps on cost ⇒ margin 3000 / 13000 of spend = 2307 bps.
    Livewire::test(Reports::class)
        ->assertTableColumnStateSet('margin_bps', '23.1%', '2026-11-16')
        ->assertTableColumnSummarySet('margin_bps', 'total', '23.1%')
        ->assertSeeHtml('data-invariant="broken"');

    Livewire::test(BillingReportStats::class, BillingReportStats::props(app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays())->totals))
        ->assertSee('23.1%')
        ->assertSee('Invariant alert: expected 20.0%');
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

it('accepts a range of exactly 366 inclusive days', function (): void {
    $filter = BillingReportFilterDTO::fromArray(['from' => '2025-11-17', 'until' => '2026-11-17']);

    expect($filter->from->toDateString())->toBe('2025-11-17');
});

it('exports one customer for a customer_id written with a plus sign', function (): void {
    ['ana' => $ana] = reportDataset();
    $url = route('filament.admin.billing.reports.export', ['from' => '2026-10-19', 'until' => '2026-11-17', 'customer_id' => '+'.$ana->id]);

    $response = $this->actingAs(User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create())->get($url)->assertOk();
    $lines = array_values(array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", $response->streamedContent()))));

    expect(array_unique(array_map(fn (array $line): string => $line[1], array_slice($lines, 1))))->toBe([(string) $ana->id]);
});

it('searches every customer by name or email in the customer select, at most 50 at a time', function (): void {
    Customer::factory()->count(3)->create();
    $zoe = Customer::factory()->create(['name' => 'Zoë Zimmer', 'email' => 'zoe@example.com']);

    expect(array_keys(Reports::searchCustomers('zoe@')))->toBe([$zoe->id])
        ->and(array_keys(Reports::searchCustomers('Zimmer')))->toBe([$zoe->id]);

    Customer::factory()->count(Reports::CUSTOMER_OPTIONS + 5)->create();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    expect(Reports::searchCustomers(''))->toHaveCount(Reports::CUSTOMER_OPTIONS);

    // A customer beyond the first page of options is still selectable and keeps its label.
    Livewire::test(Reports::class)
        ->fillForm(['group_by' => 'customer', 'customer' => $zoe->id])
        ->assertSet('filters.customer_id', $zoe->id)
        ->assertSchemaStateSet(['customer' => $zoe->id], 'form');
});

it('shows the reconciled badge only for a day the cached Pixelforge check matches, with no provider call', function (): void {
    $customer = Customer::factory()->create();
    $usage = UsageRecord::factory()->settled(59_230_000)->create(['customer_id' => $customer->id, 'billing_date' => '2026-11-16', 'input_text_tokens' => 1, 'input_image_tokens' => 2, 'output_tokens' => 3, 'reasoning_tokens' => 4]);
    UsageRecord::factory()->settled(10_000_000)->create(['customer_id' => $customer->id, 'billing_date' => '2026-11-15', 'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]);
    $entry = fn (int $generations, int $cost, array $tokens): array => ['fetched_at' => '2026-11-17T08:00:00+00:00', 'totals' => [
        'generations' => $generations, 'input_text_tokens' => $tokens[0], 'input_image_tokens' => $tokens[1],
        'output_tokens' => $tokens[2], 'reasoning_tokens' => $tokens[3], 'cost_nanos' => $cost,
    ]];
    Cache::put(GetPixelforgeReconciliationQuery::dayKey('2026-11-16'), $entry(1, 59_230_000, [1, 2, 3, 4]), 300);
    Cache::put(GetPixelforgeReconciliationQuery::dayKey('2026-11-15'), $entry(1, 9_000_000, [1, 0, 0, 0]), 300);
    // Never checked: settled usage, no cached figures.
    UsageRecord::factory()->settled(10_000_000)->create(['customer_id' => $customer->id, 'billing_date' => '2026-11-14', 'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]);
    // Pending: cached figures that match, but a take of that day is still in flight.
    UsageRecord::factory()->settled(10_000_000)->create(['customer_id' => $customer->id, 'billing_date' => '2026-11-13', 'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]);
    Cache::put(GetPixelforgeReconciliationQuery::dayKey('2026-11-13'), $entry(1, 10_000_000, [1, 0, 0, 0]), 300);
    Take::factory()->processing()->create(['customer_id' => $customer->id, 'created_at' => '2026-11-13 10:00:00']);
    // Today: still open, so Pending even with matching cached figures.
    UsageRecord::factory()->settled(10_000_000)->create(['customer_id' => $customer->id, 'billing_date' => '2026-11-17', 'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]);
    Cache::put(GetPixelforgeReconciliationQuery::dayKey('2026-11-17'), ['fetched_at' => '2026-11-17T11:00:00+00:00', 'totals' => $entry(1, 10_000_000, [1, 0, 0, 0])['totals']], 300);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(Reports::class)
        ->assertCanSeeTableRecords(['2026-11-17', '2026-11-16', '2026-11-15', '2026-11-14', '2026-11-13'])
        ->assertTableColumnStateSet('reconciled', 'Reconciled', '2026-11-16')
        ->assertTableColumnStateNotSet('reconciled', 'Reconciled', '2026-11-15')
        ->assertTableColumnStateNotSet('reconciled', 'Reconciled', '2026-11-14')
        ->assertTableColumnStateNotSet('reconciled', 'Reconciled', '2026-11-13')
        ->assertTableColumnStateNotSet('reconciled', 'Reconciled', '2026-11-17')
        ->assertSeeHtml(PixelforgeUsage::getUrl());

    Http::assertNothingSent();
    expect($usage->billing_date?->toDateString())->toBe('2026-11-16');
});

it('allocates promo and positive adjustment credits before purchases (D-084), in the report and the CSV', function (): void {
    $customer = Customer::factory()->create(['name' => 'Promo user']);
    $charge = function (string $day, int $costNanos) use ($customer): void {
        $usage = UsageRecord::factory()->settled($costNanos)->create(['customer_id' => $customer->id, 'billing_date' => $day]);
        LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::Charge, 'amount_nanos' => -$usage->charge_nanos, 'usage_record_id' => $usage->id]);
    };
    // Ledger order: $1.00 promo, $10.00 purchase, $0.75 charge, +$0.50 adjustment, −$0.10 adjustment, $0.75 charge, $0.75 charge.
    LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::PromoGrant, 'amount_nanos' => 1_000_000_000]);
    LedgerEntry::factory()->purchase()->create(['customer_id' => $customer->id, 'amount_nanos' => 10_000_000_000]);
    $charge('2026-11-15', 600_000_000);
    LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::Adjustment, 'amount_nanos' => 500_000_000, 'reason' => 'goodwill']);
    LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::Adjustment, 'amount_nanos' => -100_000_000, 'reason' => 'correction']);
    $charge('2026-11-16', 600_000_000);
    $charge('2026-11-17', 600_000_000);

    // Promo pool: 1.00 → 0.25 after the first charge → 0.75 → 0.65 → 0 (0.65 used) → 0.
    $report = app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays());
    $byDay = collect($report->rows)->mapWithKeys(fn ($row): array => [$row->key => $row->promoConsumed->toInt()])->all();

    expect($byDay)->toBe(['2026-11-17' => 0, '2026-11-16' => 650_000_000, '2026-11-15' => 750_000_000])
        ->and($report->totals->promoConsumed->toInt())->toBe(1_400_000_000);

    $lines = exportLines(User::factory()->admin(AdminRole::Operator)->create(), BillingReportFilterDTO::lastThirtyDays());
    $promoNanos = array_map(fn (array $line): string => $line[13], array_slice($lines, 1));

    expect(array_slice($lines[0], -2))->toBe(['promo_consumed', 'promo_consumed_nanos'])
        ->and($promoNanos)->toBe(['750000000', '650000000', '0'])
        ->and($lines[1][12])->toBe('0.750000000');

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
    Livewire::test(Reports::class)
        ->assertTableColumnFormattedStateSet('promo_consumed_nanos', '$0.65', '2026-11-16')
        ->assertTableColumnSummarySet('promo_consumed_nanos', 'total', '$1.40');

    Livewire::test(BillingReportStats::class, BillingReportStats::props($report->totals))
        ->assertSee('$1.40');
});

it('renders the page over HTTP as native Filament components: filters form, stats and table', function (): void {
    reportDataset();

    $html = (string) $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())
        ->get(Reports::getUrl())->assertOk()->getContent();

    expect($html)->toContain('fi-ta-table')
        ->toContain('fi-wi-stats-overview')
        ->toContain('fi-fo-toggle-buttons')
        ->toContain('$0.0740375');
});

it('allocates promo credit per customer across interleaved ledgers, after a pre-range charge, under provider and customer filters (D-084)', function (): void {
    $ana = Customer::factory()->create(['name' => 'Ana']);
    $bob = Customer::factory()->create(['name' => 'Bob']);
    $credit = fn (Customer $customer, LedgerEntryType $type, int $nanos) => LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => $type, 'amount_nanos' => $nanos, 'reason' => 'test']);
    $charge = function (Customer $customer, string $day, int $costNanos, Provider $provider = Provider::Pixelforge): UsageRecord {
        $factory = UsageRecord::factory()->settled($costNanos);
        $usage = ($provider === Provider::Anthropic ? $factory->forSuggestion() : $factory)->create(['customer_id' => $customer->id, 'billing_date' => $day]);
        LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::Charge, 'amount_nanos' => -$usage->charge_nanos, 'usage_record_id' => $usage->id]);

        return $usage;
    };

    // Ledger ids interleave the two customers.
    $credit($ana, LedgerEntryType::PromoGrant, 1_000_000_000);       // Ana promo 1.00
    $credit($bob, LedgerEntryType::PromoGrant, 500_000_000);         // Bob promo 0.50
    $charge($ana, '2026-10-01', 600_000_000);                         // Ana 0.75 before the range: promo 1.00 → 0.25
    LedgerEntry::factory()->purchase()->create(['customer_id' => $bob->id, 'amount_nanos' => 10_000_000_000]); // Bob purchase 10.00
    LedgerEntry::factory()->purchase()->create(['customer_id' => $ana->id, 'amount_nanos' => 10_000_000_000]); // Ana purchase 10.00 (no overdraft)
    $charge($ana, '2026-11-16', 600_000_000);                         // Ana 0.75: 0.25 promo
    $anthropic = $charge($bob, '2026-11-16', 240_000_000, Provider::Anthropic); // Bob: whole charge from promo
    $charge($bob, '2026-11-17', 600_000_000);                         // Bob 0.75: the promo left
    $credit($ana, LedgerEntryType::Adjustment, 100_000_000);          // Ana +0.10
    $charge($ana, '2026-11-17', 600_000_000);                         // Ana 0.75: 0.10 promo

    $bobAnthropic = $anthropic->charge_nanos;
    $bobLeft = 500_000_000 - $bobAnthropic;
    $range = ['from' => '2026-10-19', 'until' => '2026-11-17'];
    $promoBy = fn (array $filter): array => collect(app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::fromArray([...$range, ...$filter]))->rows)
        ->mapWithKeys(fn ($row): array => [$row->key => $row->promoConsumed->toInt()])->all();
    $totalOf = fn (array $filter): int => app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::fromArray([...$range, ...$filter]))->totals->promoConsumed->toInt();

    expect($promoBy([]))->toBe(['2026-11-17' => $bobLeft + 100_000_000, '2026-11-16' => 250_000_000 + $bobAnthropic])
        ->and($totalOf([]))->toBe(350_000_000 + 500_000_000)
        ->and($promoBy(['group_by' => 'customer']))->toBe([(string) $ana->id => 350_000_000, (string) $bob->id => 500_000_000])
        ->and($promoBy(['provider' => 'pixelforge']))->toBe(['2026-11-17' => $bobLeft + 100_000_000, '2026-11-16' => 250_000_000])
        ->and($totalOf(['provider' => 'anthropic']))->toBe($bobAnthropic)
        ->and($promoBy(['customer_id' => $bob->id]))->toBe(['2026-11-17' => $bobLeft, '2026-11-16' => $bobAnthropic])
        ->and($totalOf(['customer_id' => $bob->id, 'provider' => 'pixelforge']))->toBe($bobLeft);

    $operator = User::factory()->admin(AdminRole::Operator)->create();
    $csv = fn (array $filter): array => collect(array_slice(exportLines($operator, BillingReportFilterDTO::fromArray([...$range, ...$filter])), 1))
        ->mapWithKeys(fn (array $line): array => [$line[0].'|'.$line[2].'|'.$line[3] => $line[13]])->all();

    expect($csv([]))->toBe([
        '2026-11-16|Ana|pixelforge' => '250000000',
        '2026-11-16|Bob|anthropic' => (string) $bobAnthropic,
        '2026-11-17|Ana|pixelforge' => '100000000',
        '2026-11-17|Bob|pixelforge' => (string) $bobLeft,
    ])
        ->and($csv(['provider' => 'pixelforge']))->toBe([
            '2026-11-16|Ana|pixelforge' => '250000000',
            '2026-11-17|Ana|pixelforge' => '100000000',
            '2026-11-17|Bob|pixelforge' => (string) $bobLeft,
        ])
        ->and($csv(['customer_id' => $bob->id]))->toBe([
            '2026-11-16|Bob|anthropic' => (string) $bobAnthropic,
            '2026-11-17|Bob|pixelforge' => (string) $bobLeft,
        ]);
});

it('repays an overdraft before a credit joins a pool, attributing the repayment to no charge (D-084)', function (): void {
    $customer = Customer::factory()->create();
    $charge = function (string $day) use ($customer): void {
        $usage = UsageRecord::factory()->settled(600_000_000)->create(['customer_id' => $customer->id, 'billing_date' => $day]);
        LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::Charge, 'amount_nanos' => -$usage->charge_nanos, 'usage_record_id' => $usage->id]);
    };

    $charge('2026-11-15');                                            // 0.75 on an empty balance: debt 0.75
    LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::Adjustment, 'amount_nanos' => 1_000_000_000, 'reason' => 'goodwill']);
    $charge('2026-11-16');                                            // +1.00 repaid 0.75 first: 0.25 promo, debt 0.50
    LedgerEntry::factory()->purchase()->create(['customer_id' => $customer->id, 'amount_nanos' => 100_000_000]); // repays 0.10 of debt
    $charge('2026-11-17');                                            // no promo left: debt 1.15
    LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::PromoGrant, 'amount_nanos' => 1_000_000_000]);

    $rows = collect(app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays())->rows)
        ->mapWithKeys(fn ($row): array => [$row->key => $row->promoConsumed->toInt()])->all();

    expect($rows)->toBe(['2026-11-17' => 0, '2026-11-16' => 250_000_000, '2026-11-15' => 0]);
});

it('renders the stats widget without polling', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(BillingReportStats::class, BillingReportStats::props(app(GetBillingReportQuery::class)->execute(BillingReportFilterDTO::lastThirtyDays())->totals))
        ->assertOk()
        ->assertDontSeeHtml('wire:poll');
});
