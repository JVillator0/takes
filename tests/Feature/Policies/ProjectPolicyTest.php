<?php

declare(strict_types=1);

use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets customers and restricted customers manage their own projects', function (string $state): void {
    $customer = Customer::factory()->{$state}()->create();
    $project = Project::factory()->create(['customer_id' => $customer->id]);
    $gate = Gate::forUser($customer);

    expect($gate->allows('viewAny', Project::class))->toBeTrue()
        ->and($gate->allows('create', Project::class))->toBeTrue()
        ->and($gate->allows('view', $project))->toBeTrue()
        ->and($gate->allows('update', $project))->toBeTrue();
})->with(['withRole', 'restricted']);

it('hides another customer\'s project as 404', function (): void {
    $project = Project::factory()->create();
    $gate = Gate::forUser(Customer::factory()->withRole()->create());

    expect($gate->inspect('view', $project)->status())->toBe(404)
        ->and($gate->inspect('update', $project)->status())->toBe(404);
});

it('denies with account_restricted without projects.manage', function (): void {
    Role::findByName('customer', 'customer')->revokePermissionTo(CustomerPermission::ProjectsManage->value);
    $customer = Customer::factory()->withRole()->create();

    expect(Gate::forUser($customer)->inspect('create', Project::class)->code())->toBe(DenialReason::AccountRestricted->value);
});
