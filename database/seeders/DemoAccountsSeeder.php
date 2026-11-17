<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Auth\DemoAccount;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\PurchaseStatus;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Showcase customer and fixed-state demo accounts (spec 3-01 issue 4,
 * D-037, D-045). Seeded by hand, never on deploy (D-068). Idempotent: an
 * account is created once and credited only while it has no ledger entry, so
 * re-running never doubles a balance or resets one people have used.
 *
 * Dataset rules: money always goes through ledger entries. Locally, in CI and
 * for screenshots the Demo customer gets a $10.00 `promo_grant` (demo
 * dataset); in production it gets a $10.00 paid purchase instead, and no
 * takes or usage records are ever seeded there. The showcase customer gets
 * no credit: an operator tops it up and its history comes from real calls.
 * The showcase is read-only (role `restricted`, D-072); spec 9's smoke test
 * unlocks it with `takes:showcase --unlock` and locks it again.
 */
final class DemoAccountsSeeder extends Seeder
{
    public const string SEED_REASON = 'demo_seed';

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        foreach (DemoAccount::cases() as $account) {
            DB::transaction(function () use ($account): void {
                $customer = $this->findOrCreateCustomer($account);

                if ($account->startingCreditNanos() > 0 && ! $customer->ledgerEntries()->exists()) {
                    $this->credit($customer, $account->startingCreditNanos());
                }
            });
        }
    }

    /**
     * Creates the account once. On every run it re-asserts the public
     * password, so a shared login always works (D-072); it assigns the
     * account's default role only when it has none, so an operator's (or
     * `takes:showcase`'s) later choice survives a re-run.
     */
    private function findOrCreateCustomer(DemoAccount $account): Customer
    {
        $password = Config::string('takes.demo.password');
        $customer = Customer::query()->where('email', $account->email())->first();

        if ($customer === null) {
            $customer = new Customer;
            $customer->forceFill([
                'name' => $account->customerName(),
                'email' => $account->email(),
                'password' => $password,
            ])->save();
        } elseif (! Hash::check($password, $customer->password)) {
            $customer->forceFill(['password' => $password])->save();
        }

        if ($customer->roles()->doesntExist()) {
            $customer->assignRole($account->defaultRole()->value);
        }

        return $customer;
    }

    /**
     * Writes the ledger entry and the cached balance together (D-024).
     *
     * TODO(3-03): switch to RecordLedgerEntryAction (and PurchaseCreditsAction
     * for the production purchase) once spec 3-03's ledger actions are merged,
     * so the ledger keeps a single writer (money rule M-07).
     */
    private function credit(Customer $customer, int $amountNanos): void
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

        $entry = new LedgerEntry;

        if (app()->isProduction()) {
            $purchase = new Purchase;
            $purchase->forceFill([
                'customer_id' => $customer->id,
                'reference' => 'DEMO-'.Str::upper(Str::random(10)),
                'amount_nanos' => $amountNanos,
                'status' => PurchaseStatus::Paid,
                'paid_at' => now(),
                'idempotency_key' => (string) Str::uuid(),
            ])->save();

            $entry->forceFill([
                'type' => LedgerEntryType::Purchase,
                'purchase_id' => $purchase->id,
            ]);
        } else {
            $entry->forceFill([
                'type' => LedgerEntryType::PromoGrant,
                'reason' => self::SEED_REASON,
            ]);
        }

        $entry->forceFill([
            'customer_id' => $customer->id,
            'amount_nanos' => $amountNanos,
        ])->save();

        $customer->forceFill([
            'balance_nanos' => $customer->balance_nanos + $amountNanos,
        ])->save();
    }
}
