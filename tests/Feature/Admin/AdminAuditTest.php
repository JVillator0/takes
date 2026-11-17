<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\AdminRolesSeeder;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

// A07: every write an admin makes from the console lands in the audit, by table and id, with the admin as causer.
// Writes made outside the console are not the console's.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * @return list<array{event: string|null, causer: int|null, subject: string|null, resource: mixed, record: mixed}>
 */
function adminAudit(): array
{
    return Activity::query()->where('log_name', 'admin')->orderBy('id')->get()
        ->map(fn (Activity $entry): array => [
            'event' => $entry->event,
            'causer' => $entry->causer_id === null ? null : (int) $entry->causer_id,
            'subject' => $entry->subject_type,
            'resource' => $entry->properties['resource'] ?? null,
            'record' => $entry->properties['record'] ?? null,
        ])
        ->all();
}

it('records a write an admin makes while the console is serving, linking mapped models', function (): void {
    $operator = User::factory()->admin(AdminRole::Operator)->create();
    $this->actingAs($operator);
    Filament::setServingStatus();

    $customer = Customer::factory()->create();
    $customer->forceFill(['name' => 'Renamed'])->save();

    expect(adminAudit())->toBe([
        ['event' => 'created', 'causer' => $operator->id, 'subject' => 'customer', 'resource' => 'customers', 'record' => (string) $customer->id],
        ['event' => 'updated', 'causer' => $operator->id, 'subject' => 'customer', 'resource' => 'customers', 'record' => (string) $customer->id],
    ]);
});

it('records a role change by table and id', function (): void {
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    $target = User::factory()->create();
    $this->actingAs($superAdmin);
    Filament::setServingStatus();

    $target->assignRole(AdminRole::Auditor->value);

    expect(adminAudit())->toBe([
        ['event' => 'updated', 'causer' => $superAdmin->id, 'subject' => 'user', 'resource' => 'users', 'record' => (string) $target->id],
    ]);
});

it('ignores writes outside the console and a sign-in that only touches the remember token', function (): void {
    $operator = User::factory()->admin(AdminRole::Operator)->create();
    $this->actingAs($operator);

    Customer::factory()->create();

    Filament::setServingStatus();
    $operator->forceFill(['remember_token' => 'new-token'])->save();

    expect(adminAudit())->toBe([]);
});
