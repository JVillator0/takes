<?php

declare(strict_types=1);

use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\TakeFlow;

/*
| Projects CRUD (spec 4 issue 2): one field, the name; rename; archive (never
| deleted). `projects.manage` for writes; another customer's project is 404.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates a project and opens it', function (): void {
    $customer = TakeFlow::customer();

    $response = $this->actingAs($customer, 'customer')->post(route('projects.store'), ['name' => '  Lighthouse series ']);

    $project = Project::query()->sole();
    $response->assertRedirect(route('projects.show', $project));
    expect($project->name)->toBe('Lighthouse series')
        ->and($project->customer_id)->toBe($customer->id);
});

it('validates the name', function (string $name): void {
    $this->actingAs(TakeFlow::customer(), 'customer')
        ->post(route('projects.store'), ['name' => $name])
        ->assertSessionHasErrors('name');
})->with(['empty' => '', 'blank' => '   ', 'too long' => str_repeat('a', 81)]);

it('renames and archives the customer\'s own project', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);

    $this->actingAs($customer, 'customer')->patch(route('projects.update', $project), ['name' => 'Renamed'])->assertRedirect();
    $this->actingAs($customer, 'customer')->delete(route('projects.destroy', $project))->assertRedirect(route('projects.index'));

    $project->refresh();
    expect($project->name)->toBe('Renamed')
        ->and($project->archived_at)->not->toBeNull()
        ->and(Project::query()->count())->toBe(1);
});

it('is 404 for another customer\'s project', function (): void {
    $project = TakeFlow::project(TakeFlow::customer());
    $stranger = TakeFlow::customer();

    $this->actingAs($stranger, 'customer')->patch(route('projects.update', $project), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($stranger, 'customer')->delete(route('projects.destroy', $project))->assertNotFound();

    expect($project->refresh()->name)->not->toBe('Mine')
        ->and($project->archived_at)->toBeNull();
});

it('denies the read-only showcase login with account_restricted', function (): void {
    $customer = Customer::factory()->withRole(CustomerRole::Showcase)->create();

    $this->actingAs($customer, 'customer')
        ->post(route('projects.store'), ['name' => 'Nope'], ['X-Inertia' => 'true'])
        ->assertRedirect()
        ->assertSessionHasErrors('denial');

    expect(Project::query()->count())->toBe(0);
});

it('needs a signed-in customer', function (): void {
    $this->post(route('projects.store'), ['name' => 'x'])->assertRedirect(route('login'));
});
