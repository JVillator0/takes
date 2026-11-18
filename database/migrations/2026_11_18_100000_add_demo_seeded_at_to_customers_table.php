<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the seeded demo logins (D-072, #167). Demo identity used to be the
 * email alone, so DemoAccountsSeeder could adopt a customer who registered a
 * demo address first; with this stamp it adopts only accounts it created.
 *
 * Backfill: an account at a demo address without the registration welcome
 * credit was created by the seeder. That holds for every environment seeded
 * before the addresses were reserved (#167), where the welcome credit is on;
 * from now on the stamp alone decides, whatever the welcome-credit config.
 */
return new class extends Migration
{
    /** DemoAccount::emails() when this migration was written; a migration never reads app code. */
    private const array DEMO_EMAILS = ['showcase@example.com', 'demo@example.com', 'low-balance@example.com', 'new@example.com'];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestampTz('demo_seeded_at')->nullable();
        });

        DB::table('customers')
            ->whereIn('email', self::DEMO_EMAILS)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
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
