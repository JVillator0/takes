<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\Pages\ViewProject;
use App\Filament\Admin\Clusters\Studio\Resources\Projects\RelationManagers\TakesRelationManager;
use App\Models\Project;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use Database\Seeders\AdminRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Spec 7 issue 5: Studio › Projects (A09), spend = Σ settled charges of the project's takes.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
});

it('shows each project with its take count and settled spend only', function (): void {
    $project = Project::factory()->create();
    $settled = Take::factory()->succeeded()->create(['customer_id' => $project->customer_id, 'project_id' => $project->id]);
    $open = Take::factory()->processing()->create(['customer_id' => $project->customer_id, 'project_id' => $project->id]);
    UsageRecord::factory()->settled(59_230_000)->create(['customer_id' => $project->customer_id, 'billable_id' => $settled->id]);
    UsageRecord::factory()->create(['customer_id' => $project->customer_id, 'billable_id' => $open->id]);
    $empty = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$project, $empty])
        ->assertTableColumnStateSet('takes_count', 2, $project)
        ->assertTableColumnStateSet('spend_nanos', 74_037_500, $project)
        ->assertTableColumnStateSet('spend_nanos', 0, $empty)
        ->assertSee('$0.0740375');
});

it("filters projects with takes in progress and lists only that project's takes", function (): void {
    $busy = Project::factory()->create();
    $runningTake = Take::factory()->processing()->create(['customer_id' => $busy->customer_id, 'project_id' => $busy->id]);
    $idle = Project::factory()->create();
    $idleTake = Take::factory()->succeeded()->create(['customer_id' => $idle->customer_id, 'project_id' => $idle->id]);

    Livewire::test(ListProjects::class)
        ->filterTable('in_progress', true)
        ->assertCanSeeTableRecords([$busy])
        ->assertCanNotSeeTableRecords([$idle]);

    $this->get('/admin/studio/projects/'.$busy->id)->assertOk()->assertSee($busy->name);
    Livewire::test(TakesRelationManager::class, ['ownerRecord' => $busy, 'pageClass' => ViewProject::class])
        ->assertCanSeeTableRecords([$runningTake])
        ->assertCanNotSeeTableRecords([$idleTake]);
});

it('loads the projects list with a fixed number of queries', function (): void {
    Project::factory()->create();
    Livewire::test(ListProjects::class)->assertOk();

    DB::enableQueryLog();
    Livewire::test(ListProjects::class)->assertOk();
    $withOne = count(DB::getQueryLog());
    Project::factory()->count(4)->create();
    DB::flushQueryLog();
    Livewire::test(ListProjects::class)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withOne);
});

it('refuses the projects pages without the project permissions', function (): void {
    $project = Project::factory()->create();
    $admin = User::factory()->admin(AdminRole::Auditor)->create();
    $admin->roles()->first()?->revokePermissionTo(['ViewAny:Project', 'View:Project']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin->refresh())->get('/admin/studio/projects')->assertForbidden();
    $this->get('/admin/studio/projects/'.$project->id)->assertForbidden();
});
