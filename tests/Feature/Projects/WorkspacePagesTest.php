<?php

declare(strict_types=1);

use App\Enums\Auth\CustomerRole;
use App\Enums\Takes\SubmitErrorKind;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Take;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProviderLeak;
use Tests\Support\TakeFlow;
use Tests\Support\Takes;

/*
| P03 and P04 pages (spec 4 issues 2, 6, 8): the projects list with its
| default project, the workspace props spec 5 reloads (`takes`, `counts`,
| `balance`), the drawer at `?take=`, the filters, the banners, and never a
| provider name, cost or token in customer props (M-23, D-023).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    Queue::fake();
    Storage::fake();
});

it('creates "Untitled project" on the first visit and lists it', function (): void {
    $customer = TakeFlow::customer();

    $this->actingAs($customer, 'customer')
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Index')
            ->has('projects', 1)
            ->where('projects.0.name', 'Untitled project')
            ->where('projects.0.takeCount', 0)
            ->where('projects.0.spend', '$0.0000'));

    $this->actingAs($customer, 'customer')->get(route('projects.index'));
    expect(Project::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('never creates a project for the read-only showcase login', function (): void {
    $customer = Customer::factory()->withRole(CustomerRole::Showcase)->create();

    $this->actingAs($customer, 'customer')->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->has('projects', 0));
});

it('shows takes, in-progress count and settled spend per project, and searches by name', function (): void {
    $take = Takes::in('succeeded');
    Take::factory()->queued()->create(['customer_id' => $take->customer_id, 'project_id' => $take->project_id]);
    Project::factory()->create(['customer_id' => $take->customer_id, 'name' => 'Harbour']);
    $project = $take->project()->firstOrFail();

    $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->get(route('projects.index', ['search' => $project->name]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects', 1)
            ->where('projects.0.takeCount', 2)
            ->where('projects.0.inProgressCount', 1)
            // Settled charge only (holds are not spend): Takes::DOCS_CHARGE.
            ->where('projects.0.spend', '$0.3819')
            ->where('projects.0.spendExact', '$0.3818875'));
});

it('renders the workspace with the props live updates reload', function (): void {
    $take = Takes::in('processing');

    $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->get(route('projects.show', $take->project_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Show')
            ->has('takes', 1)
            ->where('takes.0.id', $take->id)
            ->where('takes.0.number', 1)
            ->where('takes.0.phase', 'processing')
            ->where('counts', ['all' => 1, 'in_progress' => 1, 'done' => 0, 'failed' => 0])
            ->where('balance.onHold', '$0.4673')
            ->where('balance.onHoldExact', '$0.46734375')
            ->where('take', null)
            ->where('hasMoreTakes', false)
            ->where('composer.maxPromptChars', 4000)
            ->where('pixelforge.configured', true));
});

it('keeps creation order newest first and filters by status group', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    $old = Take::factory()->succeeded()->create(['customer_id' => $customer->id, 'project_id' => $project->id, 'created_at' => now()->subMinutes(2)]);
    $failed = Take::factory()->failed()->create(['customer_id' => $customer->id, 'project_id' => $project->id, 'created_at' => now()->subMinute()]);
    $running = Take::factory()->create(['customer_id' => $customer->id, 'project_id' => $project->id]);

    $this->actingAs($customer, 'customer')->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('takes.0.id', $running->id)
            ->where('takes.1.id', $failed->id)
            ->where('takes.2.id', $old->id)
            ->where('takes.2.number', 1));

    $this->actingAs($customer, 'customer')->get(route('projects.show', [$project, 'status' => 'in_progress']))
        ->assertInertia(fn (Assert $page) => $page->has('takes', 1)->where('filter', 'in_progress')->where('counts.all', 3));
});

it('opens the drawer at ?take= with the exact price and lineage', function (): void {
    $take = Takes::in('succeeded');

    $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->get(route('projects.show', [$take->project_id, 'take' => $take->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('take.id', $take->id)
            ->where('take.price.amount', '$0.3818875')
            ->where('take.price.tone', 'charged')
            ->has('take.timeline'));
});

it('is the same 404 for an unknown take and another customer\'s take in the drawer', function (): void {
    $take = Takes::in('succeeded');
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);

    $this->actingAs($customer, 'customer')->get(route('projects.show', [$project, 'take' => $take->id]))->assertNotFound();
    $this->actingAs($customer, 'customer')->get(route('projects.show', [$project, 'take' => '01jc2z8q4n6v0m3h7x5t9r2b1k']))->assertNotFound();
});

it('is 404 for another customer\'s or an archived project', function (): void {
    $project = TakeFlow::project(TakeFlow::customer());
    $stranger = TakeFlow::customer();

    $this->actingAs($stranger, 'customer')->get(route('projects.show', $project))->assertNotFound();

    $own = TakeFlow::project($stranger);
    $own->forceFill(['archived_at' => now()])->save();
    $this->actingAs($stranger, 'customer')->get(route('projects.show', $own))->assertNotFound();
});

it('flags a balance below the smallest take, and a negative balance', function (): void {
    $low = TakeFlow::customer(10_000_000);

    $this->actingAs($low, 'customer')->get(route('projects.show', TakeFlow::project($low)))
        ->assertInertia(fn (Assert $page) => $page
            ->where('smallestTake.below', true)
            ->where('negativeBalance', null));

    $negative = Customer::factory()->withRole()->withCachedBalance(-50_000_000)->create();

    $this->actingAs($negative, 'customer')->get(route('projects.show', TakeFlow::project($negative)))
        ->assertInertia(fn (Assert $page) => $page->where('negativeBalance', '$0.0500'));
});

it('shares "not configured" so the banner shows and Generate is disabled without a key', function (?string $key, bool $configured): void {
    config(['pixelforge.api_key' => $key]);
    $customer = TakeFlow::customer();

    $this->actingAs($customer, 'customer')->get(route('projects.show', TakeFlow::project($customer)))
        ->assertInertia(fn (Assert $page) => $page->where('pixelforge.configured', $configured));
})->with([
    'key unset' => [null, false],
    'key set' => ['pf_test_key', true],
]);

it('never puts provider data in the workspace or drawer props', function (string $status): void {
    $take = $status === 'waiting'
        ? Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity, 'last_submit_error' => 'concurrency_limit_exceeded'])
        : Takes::in($status);

    $props = $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->get(route('projects.show', [$take->project_id, 'take' => $take->id]), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
        ->json('props');

    // `copy` is the reviewed 2-01 copy (scanned by LandingPageTest); the
    // `pixelforge.configured` boolean is the shared prop named by spec 3-04.
    unset($props['copy'], $props['pixelforge']);

    expect(ProviderLeak::find($props))->toBe([]);
})->with(['pending', 'waiting', 'queued', 'processing', 'succeeded', 'failed']);

it('shares the credit packs formatted for the Buy credits modal', function (): void {
    $customer = TakeFlow::customer();

    $this->actingAs($customer, 'customer')->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('creditPacks', [
            ['package' => 5, 'amount' => '$5.00'],
            ['package' => 10, 'amount' => '$10.00'],
            ['package' => 25, 'amount' => '$25.00'],
        ]));
});
