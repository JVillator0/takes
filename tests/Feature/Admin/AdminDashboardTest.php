<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Enums\Billing\Provider;
use App\Enums\Takes\AttentionReason;
use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\MoneyStatsOverview;
use App\Filament\Admin\Widgets\NeedsAttentionTable;
use App\Filament\Admin\Widgets\ProviderCostSplitChart;
use App\Filament\Admin\Widgets\SpendCostProfitChart;
use App\Filament\Admin\Widgets\TakesByStatusChart;
use App\Models\Customer;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Queries\Billing\GetMoneyTotalsQuery;
use App\Queries\Billing\ListDailySpendCostQuery;
use App\Queries\Billing\ListProviderCostSplitQuery;
use App\Queries\Takes\ListTakesNeedingAttentionQuery;
use App\Queries\Takes\ListTakeStatusCountsQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// D-086 / spec 7 A02: the dashboard's widgets, each read from a Query object over the database.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00:00', 'UTC'));
});

function settledUsage(int $costNanos, string $day, Provider $provider = Provider::Pixelforge): UsageRecord
{
    $factory = UsageRecord::factory();
    $factory = $provider === Provider::Anthropic ? $factory->forSuggestion() : $factory;

    return $factory->settled($costNanos)->create(['billing_date' => $day]);
}

it('totals settled spend, provider cost, profit and margin for a range of billing days', function (): void {
    settledUsage(300_000_000, '2026-11-17');
    settledUsage(400_000, '2026-11-17', Provider::Anthropic);
    settledUsage(59_230_000, '2026-11-16');
    UsageRecord::factory()->create(); // open: a hold, not spend
    UsageRecord::factory()->voided()->create(['billing_date' => '2026-11-17']);

    $today = CarbonImmutable::parse('2026-11-17', 'UTC');
    $totals = app(GetMoneyTotalsQuery::class)->execute($today, $today);

    expect($totals->spend->toInt())->toBe(375_000_000 + 500_000)
        ->and($totals->cost->toInt())->toBe(300_400_000)
        ->and($totals->profit->toInt())->toBe(75_100_000)
        ->and($totals->marginBps)->toBe(2000);

    $empty = app(GetMoneyTotalsQuery::class)->execute($today->addDay(), $today->addDay());
    expect($empty->spend->isZero())->toBeTrue()->and($empty->marginBps)->toBeNull();
});

it('lists every day of the range with spend per provider, cost and profit, zeros on empty days', function (): void {
    settledUsage(300_000_000, '2026-11-17');
    settledUsage(400_000, '2026-11-17', Provider::Anthropic);
    settledUsage(59_230_000, '2026-11-15');
    settledUsage(1_000_000, '2026-11-10'); // outside the range

    $days = app(ListDailySpendCostQuery::class)->execute(CarbonImmutable::parse('2026-11-15', 'UTC'), CarbonImmutable::parse('2026-11-17', 'UTC'));

    expect(array_map(fn ($day): string => $day->date, $days))->toBe(['2026-11-15', '2026-11-16', '2026-11-17'])
        ->and($days[0]->spendByProvider['pixelforge']->toInt())->toBe(74_037_500)
        ->and($days[0]->spendByProvider['anthropic']->toInt())->toBe(0)
        ->and($days[1]->spend->isZero())->toBeTrue()
        ->and($days[1]->cost->isZero())->toBeTrue()
        ->and($days[2]->spendByProvider['pixelforge']->toInt())->toBe(375_000_000)
        ->and($days[2]->spendByProvider['anthropic']->toInt())->toBe(500_000)
        ->and($days[2]->cost->toInt())->toBe(300_400_000)
        ->and($days[2]->profit->toInt())->toBe(75_100_000);
});

it('splits settled provider cost by provider, every provider present', function (): void {
    settledUsage(300_000_000, '2026-11-17');
    settledUsage(59_230_000, '2026-11-16');

    $split = app(ListProviderCostSplitQuery::class)->execute(CarbonImmutable::parse('2026-11-04', 'UTC'), CarbonImmutable::parse('2026-11-17', 'UTC'));

    expect(array_keys($split))->toBe(['pixelforge', 'anthropic'])
        ->and($split['pixelforge']->toInt())->toBe(359_230_000)
        ->and($split['anthropic']->toInt())->toBe(0);
});

it('counts takes created since a moment by status', function (): void {
    Take::factory()->succeeded()->count(2)->create();
    Take::factory()->failed()->create();
    Take::factory()->processing()->create();
    Take::factory()->succeeded()->create(['created_at' => now()->subDays(2)]);

    $counts = app(ListTakeStatusCountsQuery::class)->execute(CarbonImmutable::now('UTC')->startOfDay());

    expect($counts)->toBe(['pending' => 0, 'queued' => 0, 'processing' => 1, 'succeeded' => 2, 'failed' => 1]);
});

it('lists flagged and stuck takes as needing attention, from the database only', function (): void {
    $flagged = Take::factory()->succeeded()->create(['attention_reason' => AttentionReason::OverEstimate]);
    $orphan = Take::factory()->failed()->create(['attention_reason' => AttentionReason::OrphanSuccess]);
    $stuck = Take::factory()->processing()->create(['last_event_at' => now()->subMinutes(16)]);
    $retrying = Take::factory()->create(['created_at' => now()->subMinutes(20)]); // pending inside its window
    $healthy = Take::factory()->processing()->create(['last_event_at' => now()->subMinutes(2)]);

    $ids = app(ListTakesNeedingAttentionQuery::class)->execute()->pluck('id')->all();

    expect($ids)->toContain($flagged->id, $orphan->id, $stuck->id)
        ->not->toContain($retrying->id, $healthy->id);
    Http::assertNothingSent();
});

it('renders the dashboard and every widget for the auditor with exact money and no provider call', function (): void {
    settledUsage(59_230_000, '2026-11-17');
    $customer = Customer::factory()->withCachedBalance(10_000_000_000, 467_343_750)->create();
    UsageRecord::factory()->create(['customer_id' => $customer->id]);
    $flagged = Take::factory()->failed()->create(['attention_reason' => AttentionReason::OrphanSuccess]);
    $stuck = Take::factory()->processing()->create(['last_event_at' => now()->subMinutes(16)]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    $this->get('/admin')->assertOk()->assertSee('Updated 15:00 UTC');

    Livewire::test(MoneyStatsOverview::class)
        ->assertSee('$0.0740375') // spend today, exact
        ->assertSee('$0.05923') // provider cost today
        ->assertSee('$0.0148075') // profit
        ->assertSee('Margin 20.0%')
        ->assertSee('$0.46734375') // active holds
        ->assertSee('0 succeeded · 1 failed');

    Livewire::test(SpendCostProfitChart::class)->assertOk()->assertSet('filter', '14');
    Livewire::test(ProviderCostSplitChart::class)->assertOk();
    Livewire::test(TakesByStatusChart::class)->assertOk()->set('filter', '30')->assertOk();

    Livewire::test(NeedsAttentionTable::class)
        ->assertCanSeeTableRecords([$flagged, $stuck])
        ->assertTableColumnStateSet('type', 'Orphan success', $flagged)
        ->assertTableColumnStateSet('type', 'Stuck', $stuck);

    Http::assertNothingSent();
});

it('charts the exact nine-decimal amounts per day and every take status', function (): void {
    settledUsage(59_230_000, '2026-11-17');
    Take::factory()->failed()->create();

    $spend = Livewire::test(SpendCostProfitChart::class)->instance();
    $options = (fn (): array => $this->getOptions())->call($spend);

    expect($options['xAxis']['data'])->toHaveCount(14)
        ->and($options['xAxis']['data'][13])->toBe('2026-11-17')
        ->and($options['series'][0]['data'][13])->toBe('0.074037500')
        ->and($options['series'][2]['data'][13])->toBe('0.059230000')
        ->and($options['series'][3]['data'][13])->toBe('0.014807500');

    $statuses = Livewire::test(TakesByStatusChart::class)->instance();
    $byStatus = (fn (): array => $this->getOptions())->call($statuses);

    // The settled usage's factory take is still pending; the failed one is created here.
    expect($byStatus['series'][0]['data'])->toBe([1, 0, 0, 0, 1])
        ->and($byStatus['xAxis']['data'])->toBe(array_map(fn (TakeStatus $status): string => $status->getLabel(), TakeStatus::cases()));
});

it('shows the margin as - on a day with no spend', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(MoneyStatsOverview::class)->assertSee('Margin -');
});

it('gives the auditor and the operator every dashboard widget, and hides a widget without its permission', function (): void {
    $widgets = [MoneyStatsOverview::class, SpendCostProfitChart::class, ProviderCostSplitChart::class, TakesByStatusChart::class, NeedsAttentionTable::class];

    foreach ([AdminRole::Auditor, AdminRole::Operator] as $role) {
        $permissions = Role::findByName($role->value, AdminRole::GUARD)->permissions->pluck('name')->all();
        foreach ($widgets as $widget) {
            expect($permissions)->toContain('View:'.class_basename($widget));
        }
    }

    $auditor = User::factory()->admin(AdminRole::Auditor)->create();
    $this->actingAs($auditor);
    expect(NeedsAttentionTable::canView())->toBeTrue();

    Role::findByName(AdminRole::Auditor->value, AdminRole::GUARD)->revokePermissionTo('View:NeedsAttentionTable');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $auditor->unsetRelation('roles')->unsetRelation('permissions');

    expect(NeedsAttentionTable::canView())->toBeFalse()
        ->and(MoneyStatsOverview::canView())->toBeTrue()
        ->and((new Dashboard)->getWidgets())->toContain(NeedsAttentionTable::class);
});
