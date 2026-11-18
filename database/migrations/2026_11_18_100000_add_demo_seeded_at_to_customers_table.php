<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the seeded demo logins (D-072, #167). Demo identity used to be the
 * email alone, so DemoAccountsSeeder could adopt a customer who registered a
 * demo address first; with this stamp it adopts only accounts it created.
 *
 * Backfill: an existing account is stamped only when it carries both of
 * the seeder's own signs: the exact name the seeder gives that address (a
 * positive signal: shared demo logins cannot rename themselves, D-072, and
 * the admin console has no name field) and no registration welcome credit.
 * Anything else stays unstamped, so the next seeder run stops loudly rather
 * than adopting it. From now on the stamp alone decides.
 */
return new class extends Migration
{
    /**
     * DemoAccount email() => customerName() when this migration was written;
     * a migration never reads app code.
     */
    private const array SEEDED_NAMES = [
        'showcase@example.com' => 'Showcase customer',
        'demo@example.com' => 'Demo customer',
        'low-balance@example.com' => 'Low balance',
        'new@example.com' => 'New customer',
    ];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestampTz('demo_seeded_at')->nullable();
        });

        DB::table('customers')
            ->where(function (Builder $query): void {
                foreach (self::SEEDED_NAMES as $email => $name) {
                    $query->orWhere(fn (Builder $seeded) => $seeded->where('email', $email)->where('name', $name));
                }
            })
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('ledger_entries')
                ->whereColumn('ledger_entries.customer_id', 'customers.id')
                ->where('ledger_entries.type', 'promo_grant')
                ->where('ledger_entries.reason', 'welcome'))
            ->update(['demo_seeded_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('demo_seeded_at');
        });
    }
};
