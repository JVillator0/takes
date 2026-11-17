<?php

declare(strict_types=1);

use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Enums\Takes\TakeStatus;
use App\Events\TakeUpdated;
use App\Exceptions\Takes\TakeCannotBeCancelledException;
use App\Support\Pixelforge\SubmitGate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TakeFlow;
use Tests\Support\Takes;

/*
| Spec 4 "Cancel" (D-044): only a take waiting for capacity, with nothing
| possibly created at Pixelforge, can be cancelled; it fails `cancelled`,
| free. Anything else is 422 `cannot_cancel`; a stranger gets 404.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    Queue::fake();
});

it('cancels a take waiting for capacity: failed, cancelled, hold released, not charged', function (): void {
    Event::fake([TakeUpdated::class]);
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity, 'last_submit_error' => 'overloaded']);
    $customer = $take->customer()->firstOrFail();
    $balance = $customer->balance_nanos;

    $this->actingAs($customer, 'customer')
        ->from('/projects/'.$take->project_id)
        ->post(route('takes.cancel', $take))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $take->refresh();
    expect($take->status)->toBe(TakeStatus::Failed)
        ->and($take->failure_code)->toBe(FailureCode::Cancelled)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and($customer->refresh()->held_nanos)->toBe(0)
        ->and($customer->balance_nanos)->toBe($balance);
    Event::assertDispatched(TakeUpdated::class);
});

it('refuses with 422 cannot_cancel unless the take is waiting for capacity', function (Closure $make): void {
    $take = $make();
    $customer = $take->customer()->firstOrFail();

    $this->actingAs($customer, 'customer')
        ->postJson(route('takes.cancel', $take))
        ->assertStatus(422)
        ->assertExactJson(['code' => TakeCannotBeCancelledException::CODE, 'message' => __('takes.cancel_unavailable')]);

    expect($take->refresh()->failure_code)->not->toBe(FailureCode::Cancelled);
})->with([
    'submitting (first attempt)' => fn () => Takes::pendingWithHold(),
    'ambiguous submit in doubt' => fn () => Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Ambiguous]),
    'queued' => fn () => Takes::in('queued'),
    'succeeded' => fn () => Takes::in('succeeded'),
]);

it('refuses while a create call for the take is in flight', function (): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity]);
    expect(app(SubmitGate::class)->acquire($take)->allowed)->toBeTrue();

    $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->postJson(route('takes.cancel', $take))
        ->assertStatus(422);

    expect($take->refresh()->status)->toBe(TakeStatus::Pending);
});

it('is the same 404 for another customer and for an unknown take', function (): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity]);
    $stranger = TakeFlow::customer();

    $this->actingAs($stranger, 'customer')->post(route('takes.cancel', $take))->assertNotFound();
    $this->actingAs($stranger, 'customer')->post('/takes/01jc2z8q4n6v0m3h7x5t9r2b1k/cancel')->assertNotFound();

    expect($take->refresh()->status)->toBe(TakeStatus::Pending);
});

it('needs a signed-in customer', function (): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => SubmitErrorKind::Capacity]);

    $this->post(route('takes.cancel', $take))->assertRedirect(route('login'));
});
