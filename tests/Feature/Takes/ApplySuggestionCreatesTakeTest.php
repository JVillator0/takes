<?php

declare(strict_types=1);

use App\Actions\Suggestions\ApplySuggestionAction;
use App\Enums\Auth\DenialReason;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Jobs\SubmitTakeJob;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Suggestions;
use Tests\Support\TakeFlow;

/*
| Spec 8 step 4 through spec 4's pipeline: CreatesSuggestedTake is bound to
| CreateTakeAction, so applying a proposal creates a real take (estimate,
| hold, submit job) with its lineage, once (uuid v5 key + unique
| takes(suggestion_id), M-18), and denials keep their reason code.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    Queue::fake();
    Http::preventStrayRequests();
});

function proposedSuggestion(Customer $customer): Suggestion
{
    $base = Suggestions::baseTake($customer);

    return Suggestion::factory()->proposed()->create([
        'customer_id' => $customer->id,
        'project_id' => $base->project_id,
        'base_take_id' => $base->id,
        'proposal' => Suggestions::proposal(),
    ]);
}

it('creates one held take with the suggestion lineage, and replays it on a second apply', function (): void {
    $customer = Suggestions::customer(10_000_000_000);
    $suggestion = proposedSuggestion($customer);

    $take = app(ApplySuggestionAction::class)->execute($customer, $suggestion);
    $again = app(ApplySuggestionAction::class)->execute($customer, $suggestion->refresh());

    $usage = UsageRecord::query()->where('billable_id', $take->id)->sole();

    expect($again->id)->toBe($take->id)
        ->and($take->status)->toBe(TakeStatus::Pending)
        ->and($take->source)->toBe(TakeSource::AiChange)
        ->and($take->suggestion_id)->toBe($suggestion->id)
        ->and($take->parent_take_id)->toBe($suggestion->base_take_id)
        ->and($take->prompt)->toBe(Suggestions::proposal()['prompt'])
        ->and($usage->status)->toBe(UsageStatus::Open)
        ->and($customer->refresh()->held_nanos)->toBe($usage->max_charge_nanos)
        ->and(Take::query()->where('suggestion_id', $suggestion->id)->count())->toBe(1);

    Queue::assertPushed(SubmitTakeJob::class, 1);
    Http::assertNothingSent();
});

it('keeps the denial reason when the hold does not fit', function (): void {
    $customer = Suggestions::customer(1_000);
    $suggestion = proposedSuggestion($customer);

    expect(fn () => app(ApplySuggestionAction::class)->execute($customer, $suggestion))
        ->toThrow(fn (SuggestionDeniedException $denied) => expect($denied->reason)->toBe(DenialReason::InsufficientCredits));

    expect(Take::query()->where('suggestion_id', $suggestion->id)->exists())->toBeFalse()
        ->and($suggestion->refresh()->resulting_take_id)->toBeNull()
        ->and($customer->refresh()->held_nanos)->toBe(0);
});
