<?php

declare(strict_types=1);

use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeStatus;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Take;
use Database\Seeders\RolesAndPermissionsSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Billing;
use Tests\Support\PixelforgeWebhook;
use Tests\Support\TakeFlow;
use Tests\Support\Takes;

/*
| End to end through the real routes and jobs: compose → hold → submit →
| signed webhooks. Success charges exactly cost × 1.25 once, whatever the
| duplicates (M-05, M-12); failure is free with the hold back (M-13, D-024).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    PixelforgeWebhook::configure();
    Queue::fake();
});

/** POSTs the composer and runs the submit job: the take is queued at Pixelforge. */
function composeAndSubmit(Customer $customer, ?PromiseInterface $create = null): Take
{
    TakeFlow::fakePixelforge($create);
    $project = TakeFlow::project($customer);

    test()->actingAs($customer, 'customer')
        ->from(route('projects.show', $project))
        ->post(route('takes.store', $project), TakeFlow::payload())
        ->assertSessionHasNoErrors();

    $take = Take::query()->sole();
    TakeFlow::runSubmit($take);

    return $take->refresh();
}

it('charges exactly cost × 1.25 once, even when the success is delivered twice', function (): void {
    $customer = TakeFlow::customer();
    $take = composeAndSubmit($customer);

    expect($take->status)->toBe(TakeStatus::Queued)
        ->and($customer->refresh()->held_nanos)->toBe(TakeFlow::HOLD);

    // The docs' succeeded generation: cost 305,510,000, charge 381,887,500.
    $succeeded = PixelforgeWebhook::forTake($take, 'succeeded', 'evt_take_flow_success');
    PixelforgeWebhook::post($this, $succeeded, attempt: 1)->assertOk();
    PixelforgeWebhook::post($this, $succeeded, attempt: 2)->assertOk()->assertExactJson(['status' => 'duplicate']);
    // A second, distinct delivery of the same state changes nothing either.
    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'succeeded'))->assertOk();

    $usage = Takes::usage($take);
    $charges = LedgerEntry::query()->where('customer_id', $customer->id)->where('type', LedgerEntryType::Charge)->get();

    expect($take->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and($usage->status)->toBe(UsageStatus::Settled)
        ->and($usage->provider_cost_nanos)->toBe(Takes::DOCS_COST)
        ->and($usage->charge_nanos)->toBe(Takes::DOCS_CHARGE)
        ->and($usage->charge_nanos * 4)->toBe($usage->provider_cost_nanos * 5)
        ->and($charges)->toHaveCount(1)
        ->and($charges->first()?->amount_nanos)->toBe(-Takes::DOCS_CHARGE)
        ->and($customer->refresh()->held_nanos)->toBe(0)
        ->and($customer->balance_nanos)->toBe(Takes::FUNDED_NANOS - Takes::DOCS_CHARGE);
    Billing::assertCachedColumnsMatch($customer);
});

it('is free when the generation fails: no charge, hold released', function (): void {
    $customer = TakeFlow::customer();
    $take = composeAndSubmit($customer);

    PixelforgeWebhook::post($this, PixelforgeWebhook::forTake($take, 'failed'))->assertOk();

    expect($take->refresh()->status)->toBe(TakeStatus::Failed)
        ->and(Takes::usage($take)->status)->toBe(UsageStatus::Voided)
        ->and(Takes::usage($take)->charge_nanos)->toBe(0)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Charge)->count())->toBe(0)
        ->and($customer->refresh()->held_nanos)->toBe(0)
        ->and($customer->balance_nanos)->toBe(Takes::FUNDED_NANOS);
    Billing::assertCachedColumnsMatch($customer);
});

it('is free when Pixelforge rejects the prompt at submit', function (): void {
    $customer = TakeFlow::customer();
    $take = composeAndSubmit($customer, TakeFlow::error('content_policy_violation', 400));

    expect($take->status)->toBe(TakeStatus::Failed)
        ->and($customer->refresh()->held_nanos)->toBe(0)
        ->and($customer->balance_nanos)->toBe(Takes::FUNDED_NANOS)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Charge)->count())->toBe(0);
});
