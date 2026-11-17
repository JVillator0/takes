<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Takes\TakeStatus;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\Pages\ListTakes;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\Pages\ViewTake;
use App\Models\LedgerEntry;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Queries\Takes\ListStuckTakesQuery;
use Database\Seeders\AdminRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// Spec 7 issue 5: Studio › Takes (A09) and the take view (A06).

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
});

function settledTake(int $costNanos = 59_230_000): Take
{
    $take = Take::factory()->succeeded()->create(['submitted_at' => now()->subSeconds(28)]);
    $usage = UsageRecord::factory()->settled($costNanos)->create([
        'customer_id' => $take->customer_id,
        'billable_id' => $take->id,
        'min_cost_nanos' => 54_215_000,
        'max_cost_nanos' => 76_745_000,
        'input_text_tokens' => 16,
        'input_image_tokens' => 765,
        'output_tokens' => 1_100,
        'reasoning_tokens' => 600,
    ]);
    LedgerEntry::factory()->create([
        'customer_id' => $take->customer_id,
        'type' => LedgerEntryType::Charge,
        'amount_nanos' => -$usage->charge_nanos,
        'usage_record_id' => $usage->id,
    ]);

    return $take;
}

it('lists takes for the auditor with exact money and no provider call', function (): void {
    $take = settledTake();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(ListTakes::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$take])
        ->assertSee('$0.0740375')
        ->assertSee('$0.05923');

    Http::assertNothingSent();
});

it('loads the takes list with a fixed number of queries, whatever the number of rows', function (): void {
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
    settledTake();
    // Warm the permission cache so both runs pay the same fixed cost.
    Livewire::test(ListTakes::class)->assertOk();

    DB::enableQueryLog();
    Livewire::test(ListTakes::class)->assertOk();
    $withOne = count(DB::getQueryLog());

    foreach (range(1, 4) as $ignored) {
        settledTake();
    }

    DB::flushQueryLog();
    Livewire::test(ListTakes::class)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withOne);
});

it('flags stuck takes by the D-053 rule only', function (): void {
    $silentProcessing = Take::factory()->processing()->create(['last_event_at' => now()->subMinutes(16)]);
    $recentProcessing = Take::factory()->processing()->create(['last_event_at' => now()->subMinutes(5)]);
    $pendingRetryInWindow = Take::factory()->create(['created_at' => now()->subMinutes(20)]);
    $pendingTooLong = Take::factory()->create(['created_at' => now()->subMinutes(40)]);
    $silentQueuedWithoutEvents = Take::factory()->queued()->create(['submitted_at' => now()->subMinutes(30), 'last_event_at' => null]);
    Take::factory()->failed()->create(['created_at' => now()->subHour()]);

    expect(app(ListStuckTakesQuery::class)->builder()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$silentProcessing->id, $pendingTooLong->id, $silentQueuedWithoutEvents->id])->sort()->values()->all())
        ->not->toContain($recentProcessing->id, $pendingRetryInWindow->id);

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(ListTakes::class)
        ->filterTable('stuck', true)
        ->assertCanSeeTableRecords([$silentProcessing, $pendingTooLong, $silentQueuedWithoutEvents])
        ->assertCanNotSeeTableRecords([$recentProcessing, $pendingRetryInWindow]);
});

it('shows the A06 money as raw nanodollars with the usage record and its ledger charge', function (): void {
    $take = settledTake();
    $chargeEntry = LedgerEntry::query()->where('type', LedgerEntryType::Charge)->sole();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(ViewTake::class, ['record' => $take->id])
        ->assertOk()
        ->assertSee('54,215,000 / 76,745,000')
        ->assertSee('16 / 765 / 1,100 / 600')
        ->assertSee('59,230,000')
        ->assertSee('74,037,500')
        ->assertSee('#'.$chargeEntry->id.' · -74,037,500')
        ->assertSee($take->idempotency_key)
        ->assertDontSee('generation_failed');
});

it('shows the failure only for a failed take', function (): void {
    $take = Take::factory()->failed()->create();
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(ViewTake::class, ['record' => $take->id])
        ->assertOk()
        ->assertSee('generation_failed')
        ->assertSee('The generation failed.');
});

it('hides Reconcile now from the auditor and shows it to the operator', function (AdminRole $role, bool $visible): void {
    $take = Take::factory()->processing()->create();
    $this->actingAs(User::factory()->admin($role)->withAppAuthentication()->create());

    $page = Livewire::test(ViewTake::class, ['record' => $take->id]);

    $visible ? $page->assertActionVisible('reconcile') : $page->assertActionHidden('reconcile');
})->with([
    'auditor' => [AdminRole::Auditor, false],
    'operator' => [AdminRole::Operator, true],
]);

it('reconciles a take for the operator, reports the outcome and audits it', function (): void {
    $take = Take::factory()->processing()->create();
    $operator = User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create();
    $this->actingAs($operator);

    Livewire::test(ViewTake::class, ['record' => $take->id])
        ->callAction('reconcile')
        ->assertNotified('No change');

    $entry = Activity::query()->where('event', 'reconciled')->sole();
    expect((int) $entry->causer_id)->toBe($operator->id)
        ->and($entry->subject_type)->toBe('take')
        ->and($entry->properties['status_before'] ?? null)->toBe(TakeStatus::Processing->value);
});

it('refuses the take pages to an admin without the take permissions', function (): void {
    $take = Take::factory()->create();
    $admin = User::factory()->withAppAuthentication()->create();
    $admin->assignRole(AdminRole::Auditor->value);
    $admin->roles()->first()?->revokePermissionTo(['ViewAny:Take', 'View:Take']);

    $this->actingAs($admin)->get('/admin/studio/takes')->assertForbidden();
    $this->actingAs($admin)->get('/admin/studio/takes/'.$take->id)->assertForbidden();
});
