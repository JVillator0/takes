<?php

declare(strict_types=1);

use App\Actions\Billing\GrantWelcomeCreditAction;
use App\Enums\Auth\CustomerRole;
use App\Enums\Auth\DemoAccount;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\PurchaseStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Take;
use App\Models\UsageRecord;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function demoCustomer(DemoAccount $account): Customer
{
    return Customer::query()->where('email', $account->email())->sole();
}

it('seeds the read-only showcase customer and the fixed-state accounts', function (): void {
    $this->seed(DemoAccountsSeeder::class);

    foreach (DemoAccount::cases() as $account) {
        $customer = demoCustomer($account);

        expect($customer->name)->toBe($account->customerName())
            ->and($customer->is_demo)->toBeFalse()
            ->and($customer->demo_expires_at)->toBeNull()
            ->and($customer->getRoleNames()->all())->toBe([$account->defaultRole()->value])
            ->and(Hash::check('password', $customer->password))->toBeTrue();
    }

    // D-072: the showcase can view but not create takes or buy credits.
    expect(demoCustomer(DemoAccount::Showcase)->getRoleNames()->all())->toBe(['showcase'])
        ->and(demoCustomer(DemoAccount::DemoCustomer)->getRoleNames()->all())->toBe(['customer']);
});

it('re-asserts the configured public password on existing demo accounts', function (): void {
    $this->seed(DemoAccountsSeeder::class);
    demoCustomer(DemoAccount::DemoCustomer)->forceFill(['password' => 'changed-by-someone'])->save();

    $this->seed(DemoAccountsSeeder::class);

    expect(Hash::check('password', demoCustomer(DemoAccount::DemoCustomer)->password))->toBeTrue();
});

it('keeps an unlocked showcase unlocked on a re-run', function (): void {
    $this->seed(DemoAccountsSeeder::class);
    $this->artisan('takes:showcase', ['--unlock' => true])->assertSuccessful();

    $this->seed(DemoAccountsSeeder::class);

    expect(demoCustomer(DemoAccount::Showcase)->getRoleNames()->all())->toBe(['customer']);
});

it('gives each account its starting money through ledger entries (local dataset)', function (): void {
    $this->seed(DemoAccountsSeeder::class);

    $expected = [
        DemoAccount::Showcase->value => 0,
        DemoAccount::DemoCustomer->value => 10_000_000_000,
        DemoAccount::LowBalance->value => 10_000_000,
        DemoAccount::NewCustomer->value => 0,
    ];

    foreach (DemoAccount::cases() as $account) {
        $customer = demoCustomer($account);

        expect($customer->balance_nanos)->toBe($expected[$account->value])
            ->and((int) $customer->ledgerEntries()->sum('amount_nanos'))->toBe($customer->balance_nanos)
            ->and($customer->held_nanos)->toBe(0);
    }

    expect(LedgerEntry::query()->pluck('type')->unique()->values()->all())->toBe([LedgerEntryType::PromoGrant])
        ->and(Purchase::query()->count())->toBe(0);
});

it('is idempotent: re-running never doubles a balance or resets a used account', function (): void {
    $this->seed(DemoAccountsSeeder::class);
    $demo = demoCustomer(DemoAccount::DemoCustomer);
    $demo->forceFill(['name' => 'Renamed by someone'])->save();

    $this->seed(DemoAccountsSeeder::class);

    expect(Customer::query()->whereIn('email', DemoAccount::emails())->count())->toBe(4)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(demoCustomer(DemoAccount::DemoCustomer)->balance_nanos)->toBe(10_000_000_000)
        ->and(demoCustomer(DemoAccount::DemoCustomer)->name)->toBe('Renamed by someone');
});

it('keeps an operator-restricted demo account restricted', function (): void {
    $this->seed(DemoAccountsSeeder::class);
    demoCustomer(DemoAccount::LowBalance)->syncRoles([CustomerRole::Restricted->value]);

    $this->seed(DemoAccountsSeeder::class);

    expect(demoCustomer(DemoAccount::LowBalance)->getRoleNames()->all())->toBe(['restricted']);
});

it('seeds a paid $10 purchase and no takes or usage in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // Called directly: `db:seed` asks for confirmation in production.
    app(DemoAccountsSeeder::class)->setContainer(app())->__invoke();

    $demo = demoCustomer(DemoAccount::DemoCustomer);
    $entry = $demo->ledgerEntries()->sole();

    expect($entry->type)->toBe(LedgerEntryType::Purchase)
        ->and($entry->amount_nanos)->toBe(10_000_000_000)
        ->and($entry->purchase?->status)->toBe(PurchaseStatus::Paid)
        ->and($demo->balance_nanos)->toBe(10_000_000_000)
        ->and(Take::query()->count())->toBe(0)
        ->and(UsageRecord::query()->count())->toBe(0);

    // Spec 6: every seeded purchase (Demo, Low balance) has its invoice,
    // issued through IssueInvoiceAction with gapless numbers.
    $invoice = Invoice::query()->where('purchase_id', $entry->purchase_id)->sole();
    $paid = Purchase::query()->where('status', PurchaseStatus::Paid->value)->count();

    expect($invoice->customer_id)->toBe($demo->id)
        ->and($invoice->total_nanos)->toBe(10_000_000_000)
        ->and(Invoice::query()->count())->toBe($paid)
        ->and(Invoice::query()->orderBy('number')->pluck('number')->all())->toBe(array_map(
            fn (int $n): string => sprintf('INV-%d-%06d', now()->utc()->year, $n),
            range(1, $paid),
        ));

    // Re-running creates no second purchase and no second invoice.
    app(DemoAccountsSeeder::class)->setContainer(app())->__invoke();

    expect(Purchase::query()->count())->toBe($paid)
        ->and(Invoice::query()->count())->toBe($paid);
});

it('backfills the invoice of a paid purchase that has none, once, oldest payment first', function (): void {
    // Purchases from before invoices existed: paid, credited, no invoice.
    $customer = Customer::factory()->create();
    $older = Purchase::factory()->create(['customer_id' => $customer->id, 'paid_at' => '2026-11-10T09:00:00Z']);
    $newer = Purchase::factory()->create(['customer_id' => $customer->id, 'paid_at' => '2026-11-12T09:00:00Z']);
    Purchase::factory()->pending()->create(['customer_id' => $customer->id]);

    $this->seed(DemoAccountsSeeder::class);
    $this->seed(DemoAccountsSeeder::class);

    expect(Invoice::query()->count())->toBe(2)
        ->and($older->invoice()->sole()->number)->toBe('INV-2026-000001')
        ->and($newer->invoice()->sole()->number)->toBe('INV-2026-000002')
        ->and(DB::table('invoice_counters')->where('year', 2026)->value('last_number'))->toBe(2);
});

it('fails loudly instead of adopting a demo address someone registered first (D-072, #111)', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $squatter = Customer::factory()->create(['email' => DemoAccount::Showcase->email(), 'password' => 'squatter-secret']);
    $squatter->assignRole(CustomerRole::Customer->value);
    config(['takes.welcome_credit_nanos' => 1_000_000_000]);
    app(GrantWelcomeCreditAction::class)->execute($squatter);

    expect(fn () => $this->seed(DemoAccountsSeeder::class))
        ->toThrow(RuntimeException::class, DemoAccount::Showcase->email());

    // Nothing was adopted: the squatter keeps its own password and role.
    expect(Hash::check('password', $squatter->refresh()->password))->toBeFalse()
        ->and($squatter->getRoleNames()->all())->toBe(['customer']);
});

it('fails loudly on a squatter registered while the welcome credit was off (#167)', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['takes.welcome_credit_nanos' => 0]);
    $squatter = Customer::factory()->create(['name' => 'Squatter', 'email' => DemoAccount::DemoCustomer->email(), 'password' => 'squatter-secret']);
    $squatter->assignRole(CustomerRole::Customer->value);
    app(GrantWelcomeCreditAction::class)->execute($squatter);

    expect($squatter->ledgerEntries()->exists())->toBeFalse()
        ->and(fn () => $this->seed(DemoAccountsSeeder::class))
        ->toThrow(RuntimeException::class, DemoAccount::DemoCustomer->email());

    expect(Hash::check('password', $squatter->refresh()->password))->toBeFalse()
        ->and($squatter->ledgerEntries()->exists())->toBeFalse();
});
