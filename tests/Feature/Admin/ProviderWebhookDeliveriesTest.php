<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Enums\Webhooks\WebhookOutcome;
use App\Filament\Admin\Clusters\Providers\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Models\Take;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Queries\Webhooks\GetWebhookDeliveryStatsQuery;
use Database\Seeders\AdminRolesSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

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

it('opens the raw payload, escaped', function (): void {
    $event = WebhookEvent::factory()->create(['payload' => ['type' => 'generation.succeeded', 'note' => '<script>alert(1)</script>']]);
    $delivery = WebhookDelivery::factory()->create(['event_id' => $event->event_id, 'webhook_event_id' => $event->id]);

    Livewire::test(ListWebhookDeliveries::class)
        ->assertActionVisible(TestAction::make('viewPayload')->table($delivery))
        ->mountAction(TestAction::make('viewPayload')->table($delivery))
        ->assertHasNoErrors();

    $html = view('filament.admin.webhook-payload', [
        'json' => json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ])->render();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->not->toContain('<script>');
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
