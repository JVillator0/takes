<?php

declare(strict_types=1);

use App\Actions\Suggestions\ApplySuggestionAction;
use App\Enums\Auth\DenialReason;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Exceptions\Suggestions\SuggestionDeniedException;
use App\Jobs\SubmitTakeJob;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Takes\TakeImageUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

/**
 * A base take whose kept reference is its parent's image as a 60-minute
 * signed URL, the way a remix or an earlier apply stored it.
 */
function baseWithParentReference(Customer $customer, bool $parentPersisted): array
{
    $parent = Suggestions::baseTake($customer, [
        'reference_images' => [],
        'image_path' => $parentPersisted ? "takes/{$customer->id}/PLACEHOLDER.png" : null,
        'image_url_expires_at' => now()->addHour(),
    ]);

    // Pixelforge's signed URL on the image host, as a succeeded snapshot stores it.
    $parent->forceFill(['image_url' => "https://pixelforge.promptedit.dev/images/{$parent->pixelforge_id}.png?expires=1&signature=x"])->save();

    if ($parentPersisted) {
        $parent->forceFill(['image_path' => "takes/{$customer->id}/{$parent->id}.png"])->save();
        Storage::put($parent->image_path, 'png');
    }

    $issued = TakeImageUrl::forReference($parent);
    expect($issued)->not->toBeNull();

    $base = Suggestions::baseTake($customer, [
        'project_id' => $parent->project_id,
        'parent_take_id' => $parent->id,
        'reference_images' => [$issued, 'https://images.example/ref-1.png'],
    ]);

    $suggestion = Suggestion::factory()->proposed()->create([
        'customer_id' => $customer->id,
        'project_id' => $base->project_id,
        'base_take_id' => $base->id,
        'proposal' => Suggestions::proposal(['keep_references' => true, 'add_base_image_as_reference' => false]),
    ]);

    return [$parent, $issued, $suggestion];
}

it('re-issues a kept reference to a take image when it is applied more than an hour later', function (): void {
    Storage::fake();
    $customer = Suggestions::customer(10_000_000_000);
    [$parent, $issued, $suggestion] = baseWithParentReference($customer, parentPersisted: true);

    $this->travel(61)->minutes();
    $take = app(ApplySuggestionAction::class)->execute($customer, $suggestion);

    expect($take->reference_images)->toHaveCount(2)
        ->and($take->reference_images[0])->not->toBe($issued)
        ->and($take->reference_images[0])->toBe(TakeImageUrl::forReference($parent))
        // The customer's own URL passes through untouched.
        ->and($take->reference_images[1])->toBe('https://images.example/ref-1.png');
});

it('refuses a kept take-image reference that can no longer be resolved, with nothing held', function (): void {
    $customer = Suggestions::customer(10_000_000_000);
    [$parent, , $suggestion] = baseWithParentReference($customer, parentPersisted: false);

    // The provider URL expires and there is no persisted copy.
    $parent->forceFill(['image_url_expires_at' => now()->addMinutes(30)])->save();
    $this->travel(61)->minutes();

    expect(fn () => app(ApplySuggestionAction::class)->execute($customer, $suggestion))
        ->toThrow(BaseImageUnavailableException::class);

    expect(Take::query()->where('suggestion_id', $suggestion->id)->exists())->toBeFalse()
        ->and($customer->refresh()->held_nanos)->toBe(0)
        ->and(UsageRecord::query()->where('customer_id', $customer->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});
