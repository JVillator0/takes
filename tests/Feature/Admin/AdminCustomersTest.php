<?php

declare(strict_types=1);

use App\Actions\Auth\RestrictCustomerAction;
use App\Actions\Billing\AdjustCustomerCreditsAction;
use App\Enums\Auth\AdminRole;
use App\Enums\Auth\CustomerRole;
use App\Enums\Auth\DemoAccount;
use App\Enums\Billing\LedgerEntryType;
use App\Filament\Admin\Resources\Customers\Pages\ListCustomers;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Queries\Customers\GetCustomerStatsQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// Spec 7 issue 7 (#68): Customers (A09 table, A05 view), Adjust credits and Restrict/Unrestrict, audited.

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
});

/** A customer with $10.00 (ledger-backed), $0.314 held and one settled take. */
function fundedCustomer(string $name = 'Ana López'): Customer
{
    $customer = Customer::factory()->withRole()->create(['name' => $name]);
    LedgerEntry::factory()->create(['customer_id' => $customer->id, 'type' => LedgerEntryType::PromoGrant, 'amount_nanos' => 10_000_000_000]);
    $customer->forceFill(['balance_nanos' => 10_000_000_000, 'held_nanos' => 314_000_000])->save();
    $take = Take::factory()->succeeded()->create(['customer_id' => $customer->id]);
    UsageRecord::factory()->settled(59_230_000)->create(['customer_id' => $customer->id, 'billable_id' => $take->id]);

    return $customer;
}

function operator(): User
{
    return User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create();
}

it('lists customers for the auditor with exact money and no write action', function (): void {
    $ana = fundedCustomer();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(ListCustomers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ana])
        ->assertSee('$9.686')        // available = 10.00 − 0.314
        ->assertSee('$0.314')        // on hold
        ->assertSee('$0.0740375')    // lifetime spend (59,230,000 × 1.25)
        ->assertSee('$0.0148075')    // profit
        ->assertActionHidden(TestAction::make('adjustCredits')->table($ana))
        ->assertActionHidden(TestAction::make('restrict')->table($ana));

    Http::assertNothingSent();
});

it('loads the customers list with a fixed number of queries', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
    fundedCustomer('One');
    Livewire::test(ListCustomers::class)->assertOk();

    DB::enableQueryLog();
    Livewire::test(ListCustomers::class)->assertOk();
    $withOne = count(DB::getQueryLog());

    foreach (['Two', 'Three', 'Four'] as $name) {
        fundedCustomer($name);
    }

    DB::flushQueryLog();
    Livewire::test(ListCustomers::class)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withOne);
});

it('adjusts credits through one ledger adjustment with the admin, the reason and an audit entry', function (): void {
    $ana = fundedCustomer();
    $operator = operator();
    $this->actingAs($operator);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])
        ->callAction('adjustCredits', ['amount' => '+2.00', 'reason' => 'Refund for broken reference image, ticket #12'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $entry = LedgerEntry::query()->where('type', LedgerEntryType::Adjustment)->sole();
    $audit = Activity::query()->where('event', AdjustCustomerCreditsAction::EVENT)->sole();

    expect($entry->amount_nanos)->toBe(2_000_000_000)
        ->and($entry->admin_id)->toBe($operator->id)
        ->and($entry->reason)->toBe('Refund for broken reference image, ticket #12')
        ->and($ana->refresh()->balance_nanos)->toBe(12_000_000_000)
        ->and($ana->balance_nanos)->toBe((int) LedgerEntry::query()->where('customer_id', $ana->id)->sum('amount_nanos'))
        ->and((int) $audit->causer_id)->toBe($operator->id)
        ->and($audit->properties['reason'] ?? null)->toBe('Refund for broken reference image, ticket #12')
        ->and($audit->properties['ledger_entry_id'] ?? null)->toBe($entry->id);
});

it('requires a reason and a non-zero amount with at most 9 decimals', function (array $data, string $field): void {
    $ana = fundedCustomer();
    $this->actingAs(operator());

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])
        ->callAction('adjustCredits', $data)
        ->assertHasFormErrors([$field]);

    expect(LedgerEntry::query()->where('type', LedgerEntryType::Adjustment)->exists())->toBeFalse();
})->with([
    'no reason' => [['amount' => '2.00', 'reason' => ''], 'reason'],
    'zero' => [['amount' => '0.00', 'reason' => 'x'], 'amount'],
    'not a number' => [['amount' => 'two', 'reason' => 'x'], 'amount'],
    'ten decimals' => [['amount' => '0.0000000001', 'reason' => 'x'], 'amount'],
]);

it('refuses a negative adjustment that would take held credit', function (): void {
    $ana = fundedCustomer();
    $this->actingAs(operator());

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])
        ->callAction('adjustCredits', ['amount' => '-9.70', 'reason' => 'chargeback'])
        ->assertNotified('Only $9.686 is available; held credit cannot be taken back.');

    expect($ana->refresh()->balance_nanos)->toBe(10_000_000_000)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Adjustment)->exists())->toBeFalse();
});

it('restricts and unrestricts a customer with a reason, audited', function (): void {
    $ana = fundedCustomer();
    $this->actingAs(operator());

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])
        ->callAction('restrict', ['reason' => 'abuse report #7'])
        ->assertHasNoFormErrors();
    expect($ana->refresh()->getRoleNames()->all())->toBe([CustomerRole::Restricted->value]);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])
        ->callAction('restrict', ['reason' => 'resolved'])
        ->assertHasNoFormErrors();
    expect($ana->refresh()->getRoleNames()->all())->toBe([CustomerRole::Customer->value])
        ->and(Activity::query()->whereIn('event', [RestrictCustomerAction::RESTRICTED, RestrictCustomerAction::UNRESTRICTED])->pluck('event')->all())
        ->toBe([RestrictCustomerAction::RESTRICTED, RestrictCustomerAction::UNRESTRICTED]);
});

it('never offers Restrict on a shared demo login', function (): void {
    $showcase = Customer::factory()->withRole(CustomerRole::Showcase)->create(['email' => DemoAccount::Showcase->email()]);
    $this->actingAs(operator());

    Livewire::test(ViewCustomer::class, ['record' => $showcase->id])->assertActionHidden('restrict');
    expect(fn () => app(RestrictCustomerAction::class)->execute(operator(), $showcase, true, 'x'))->toThrow(InvalidArgumentException::class);
});

it('filters low available and takes in progress', function (): void {
    $rich = fundedCustomer('Rich');
    $poor = Customer::factory()->withRole()->create(['name' => 'Poor', 'balance_nanos' => 0]);
    Take::factory()->processing()->create(['customer_id' => $poor->id]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(ListCustomers::class)
        ->filterTable('low_available', true)
        ->assertCanSeeTableRecords([$poor])
        ->assertCanNotSeeTableRecords([$rich])
        ->resetTableFilters()
        ->filterTable('in_progress', true)
        ->assertCanSeeTableRecords([$poor])
        ->assertCanNotSeeTableRecords([$rich]);
});

it('writes Last active at most every five minutes from customer requests', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $customer = Customer::factory()->withRole()->create(['last_active_at' => null]);

    $this->actingAs($customer, 'customer')->get('/billing')->assertOk();
    expect($customer->refresh()->last_active_at?->toDateTimeString())->toBe('2026-11-17 12:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:03:00', 'UTC'));
    $this->actingAs($customer->refresh(), 'customer')->get('/billing')->assertOk();
    expect($customer->refresh()->last_active_at?->toDateTimeString())->toBe('2026-11-17 12:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:06:00', 'UTC'));
    $this->actingAs($customer->refresh(), 'customer')->get('/billing')->assertOk();
    expect($customer->refresh()->last_active_at?->toDateTimeString())->toBe('2026-11-17 12:06:00');
});

it('sums outstanding credits and active holds for the header stats', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    fundedCustomer('One')->forceFill(['last_active_at' => now()])->save();
    fundedCustomer('Two')->forceFill(['last_active_at' => now()->subDays(2)])->save();

    $stats = app(GetCustomerStatsQuery::class)->execute();

    expect([$stats->customers, $stats->activeToday, $stats->outstandingCredits->toInt(), $stats->activeHolds->toInt()])
        ->toBe([2, 1, 20_000_000_000, 628_000_000]);
});
