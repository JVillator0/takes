<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Enums\Webhooks\WebhookOutcome;
use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Actions\ViewPayloadAction;
use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\Pages\ViewTake;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\RelationManagers\WebhookDeliveriesRelationManager;
use App\Filament\Admin\Widgets\WebhookDeliveryStatsOverview;
use App\Models\Take;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Queries\Webhooks\GetWebhookDeliveryStatsQuery;
use Database\Seeders\AdminRolesSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

// Spec 7 § Providers › Webhook events (A11, D-027): every delivery with its outcome, stats over 24 hours.

beforeEach(function (): void {
    $this->seed(AdminRolesSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
});

it('lists deliveries with their outcome and filters by outcome', function (): void {
    $take = Take::factory()->succeeded()->create();
    $applied = WebhookDelivery::factory()->create(['take_id' => $take->id]);
    $duplicate = WebhookDelivery::factory()->duplicate()->create([
        'event_id' => $applied->event_id,
        'webhook_event_id' => $applied->webhook_event_id,
        'take_id' => $take->id,
    ]);

    Livewire::test(ListWebhookDeliveries::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$applied, $duplicate])
        ->filterTable('outcome', [WebhookOutcome::Duplicate->value])
        ->assertCanSeeTableRecords([$duplicate])
        ->assertCanNotSeeTableRecords([$applied]);
});

it('opens the raw payload in a read-only, wrapping, scrollable field, escaped', function (): void {
    $imageUrl = 'https://pixelforge.example/images/gen_123.png?X-Signature='.str_repeat('a1b2c3d4', 40);
    $event = WebhookEvent::factory()->create(['payload' => [
        'type' => 'generation.succeeded',
        'note' => '<script>alert(1)</script>',
        'data' => ['object' => ['image_url' => $imageUrl]],
    ]]);
    $delivery = WebhookDelivery::factory()->create(['event_id' => $event->event_id, 'webhook_event_id' => $event->id]);
    // jsonb keeps its own key order: compare with the payload as stored.
    $json = json_encode($event->refresh()->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    Livewire::test(ListWebhookDeliveries::class)
        ->assertActionVisible(TestAction::make('viewPayload')->table($delivery))
        ->mountAction(TestAction::make('viewPayload')->table($delivery))
        ->assertHasNoErrors()
        ->assertSchemaStateSet(['payload' => $json], 'mountedActionSchema0')
        // A read-only textarea with a fixed height: long lines (the signed image_url) wrap and it scrolls.
        ->assertMountedActionModalSeeHtml([
            'data-testid="webhook-payload"',
            'readonly',
            'rows="'.ViewPayloadAction::ROWS.'"',
            'overflow-wrap: anywhere',
        ])
        // The JSON reaches the textarea as its value (field state), never as markup.
        ->assertMountedActionModalDontSeeHtml('<script>alert(1)</script>');
});

it('counts the last 24 hours of events and outcomes', function (): void {
    WebhookDelivery::factory()->count(2)->create();
    WebhookDelivery::factory()->duplicate()->create();
    WebhookDelivery::factory()->create(['outcome' => WebhookOutcome::IgnoredStale]);
    WebhookDelivery::factory()->create(['outcome' => WebhookOutcome::UnknownTake]);
    WebhookDelivery::factory()->create(['outcome' => WebhookOutcome::OrphanSuccess]);
    $old = WebhookEvent::factory()->create(['received_at' => now()->subDays(2)]);
    WebhookDelivery::factory()->duplicate()->create([
        'event_id' => $old->event_id,
        'webhook_event_id' => $old->id,
        'received_at' => now()->subDays(2),
    ]);

    $stats = app(GetWebhookDeliveryStatsQuery::class)->execute();

    expect([$stats->events, $stats->duplicates, $stats->ignoredStale, $stats->unknownTake, $stats->orphanSuccess])
        ->toBe([6, 1, 1, 1, 1]);
});

it('filters by take and loads with a fixed number of queries', function (): void {
    $take = Take::factory()->succeeded()->create();
    $mine = WebhookDelivery::factory()->create(['take_id' => $take->id]);
    $other = WebhookDelivery::factory()->create();

    Livewire::test(ListWebhookDeliveries::class)
        ->filterTable('take', ['take_id' => $take->id])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(WebhookDeliveryStatsOverview::class)->assertSee('Events (24 h)');

    DB::enableQueryLog();
    Livewire::test(ListWebhookDeliveries::class)->assertOk();
    $withTwo = count(DB::getQueryLog());
    WebhookDelivery::factory()->count(4)->create();
    DB::flushQueryLog();
    Livewire::test(ListWebhookDeliveries::class)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withTwo);
});

it('refuses the deliveries page and hides its stats without their permissions', function (): void {
    $admin = User::factory()->admin(AdminRole::Auditor)->create();
    $this->actingAs($admin);
    expect(WebhookDeliveryStatsOverview::canView())->toBeTrue();

    $admin->roles()->first()?->revokePermissionTo(['ViewAny:WebhookDelivery', 'View:WebhookDelivery', 'View:WebhookDeliveryStatsOverview']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($admin->refresh());

    expect(WebhookDeliveryStatsOverview::canView())->toBeFalse();
    $this->get('/admin/providers/webhook-deliveries')->assertForbidden();
});

it('offers no take filter on the take view, where the deliveries are already one take\'s', function (): void {
    $take = Take::factory()->succeeded()->create();
    WebhookDelivery::factory()->create(['take_id' => $take->id]);

    $relationManager = Livewire::test(WebhookDeliveriesRelationManager::class, ['ownerRecord' => $take, 'pageClass' => ViewTake::class])
        ->assertTableFilterExists('outcome');

    expect($relationManager->instance()->getTable()->getFilter('take'))->toBeNull();
    Livewire::test(ListWebhookDeliveries::class)->assertTableFilterExists('take');
});
