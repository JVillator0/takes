<?php

declare(strict_types=1);

use App\Actions\Projects\ArchiveProjectAction;
use App\Actions\Takes\CreateTakeAction;
use App\DTOs\Takes\CreateTakeDTO;
use App\Enums\Auth\CustomerRole;
use App\Enums\Auth\DenialReason;
use App\Enums\Billing\UsageStatus;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Projects\ProjectArchivedException;
use App\Jobs\ReconcileTakeJob;
use App\Jobs\SubmitTakeJob;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Project;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Takes\TakeImageUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Billing;
use Tests\Support\TakeFlow;

/*
| Spec 4 "Create take" (D-033, M-18, M-19, D-050): the composer's key makes a
| double click or a resubmit one take with one hold and one submit; denials
| come back with their DenialReason code and write nothing.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    TakeFlow::fakePixelforge();
    Queue::fake();
});

function createTake(Customer $customer, ?Project $project = null, array $overrides = []): TestResponse
{
    $project ??= TakeFlow::project($customer);

    return test()->actingAs($customer, 'customer')
        ->from(route('projects.show', $project))
        ->post(route('takes.store', $project), TakeFlow::payload($overrides));
}

it('creates a pending take that holds its max price, recomputed from the real prompt', function (): void {
    $customer = TakeFlow::customer();

    createTake($customer)->assertRedirect()->assertSessionHasNoErrors();

    $take = Take::query()->sole();
    $usage = UsageRecord::query()->sole();

    expect($take->status)->toBe(TakeStatus::Pending)
        ->and($take->source)->toBe(TakeSource::Composer)
        ->and($take->prompt)->toBe(TakeFlow::PROMPT)
        ->and($usage->status)->toBe(UsageStatus::Open)
        ->and($usage->max_charge_nanos)->toBe(TakeFlow::HOLD)
        ->and($usage->min_charge_nanos)->toBe(TakeFlow::MIN_CHARGE)
        ->and($customer->refresh()->held_nanos)->toBe(TakeFlow::HOLD)
        // Holds are state, never ledger rows (D-024).
        ->and(LedgerEntry::query()->where('customer_id', $customer->id)->count())->toBe(1);

    Queue::assertPushed(SubmitTakeJob::class, fn (SubmitTakeJob $job): bool => $job->takeId === $take->id);
    Queue::assertPushed(ReconcileTakeJob::class, fn (ReconcileTakeJob $job): bool => $job->takeId === $take->id);
    Billing::assertCachedColumnsMatch($customer);
});

it('replays the same key into the same take: one take, one hold, one submit job', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    $key = (string) Str::uuid();

    createTake($customer, $project, ['idempotency_key' => $key])->assertRedirect();
    createTake($customer, $project, ['idempotency_key' => $key])->assertRedirect()->assertSessionHasNoErrors();

    expect(Take::query()->count())->toBe(1)
        ->and(UsageRecord::query()->count())->toBe(1)
        ->and($customer->refresh()->held_nanos)->toBe(TakeFlow::HOLD);

    Queue::assertPushed(SubmitTakeJob::class, 1);
});

it('makes one provider call for two identical posts', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    $key = (string) Str::uuid();

    createTake($customer, $project, ['idempotency_key' => $key]);
    createTake($customer, $project, ['idempotency_key' => $key]);

    Queue::assertPushed(SubmitTakeJob::class, 1);
    TakeFlow::runSubmit(Take::query()->sole());

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->hasHeader('Idempotency-Key', $key));
    expect(Take::query()->sole()->status)->toBe(TakeStatus::Queued);
});

it('treats the same key from another customer as a different take', function (): void {
    $key = (string) Str::uuid();
    $first = TakeFlow::customer();
    $second = TakeFlow::customer();

    createTake($first, overrides: ['idempotency_key' => $key]);
    createTake($second, overrides: ['idempotency_key' => $key]);

    expect(Take::query()->count())->toBe(2)
        ->and($first->refresh()->held_nanos)->toBe(TakeFlow::HOLD)
        ->and($second->refresh()->held_nanos)->toBe(TakeFlow::HOLD);
});

it('denies insufficient credits with the code and writes nothing', function (): void {
    $customer = TakeFlow::customer(TakeFlow::HOLD - 1);

    createTake($customer)->assertRedirect()->assertSessionHasErrors([
        'denial' => DenialReason::InsufficientCredits->message(),
    ]);

    expect(Take::query()->count())->toBe(0)
        ->and(UsageRecord::query()->count())->toBe(0)
        ->and($customer->refresh()->held_nanos)->toBe(0)
        ->and(LedgerEntry::query()->where('customer_id', $customer->id)->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('denies with the policy reason code, 422 {code, message} for a JSON caller', function (DenialReason $reason, Closure $setUp): void {
    $customer = TakeFlow::customer();
    $setUp($customer);
    $project = TakeFlow::project($customer);

    test()->actingAs($customer, 'customer')
        ->postJson(route('takes.store', $project), TakeFlow::payload())
        ->assertStatus(422)
        ->assertExactJson(['code' => $reason->value, 'message' => $reason->message()]);

    expect(UsageRecord::query()->count())->toBe(0);
})->with([
    'restricted' => [DenialReason::AccountRestricted, function (Customer $customer): void {
        $customer->syncRoles([CustomerRole::Restricted->value]);
    }],
    'per-customer cap' => [DenialReason::DailyTakeCap, function (Customer $customer): void {
        config(['takes.daily_cap_per_customer' => 2]);
        Take::factory()->count(2)->create(['customer_id' => $customer->id]);
    }],
    'global cap' => [DenialReason::DemoQuotaReached, function (Customer $customer): void {
        config(['takes.daily_cap_global' => 3]);
        Take::factory()->count(3)->create();
    }],
]);

it('is 403 for a project or a parent take of another customer', function (): void {
    $customer = TakeFlow::customer();
    $other = TakeFlow::customer();

    test()->actingAs($customer, 'customer')
        ->post(route('takes.store', TakeFlow::project($other)), TakeFlow::payload())
        ->assertForbidden();

    $othersTake = Take::factory()->succeeded()->create(['customer_id' => $other->id]);

    test()->actingAs($customer, 'customer')
        ->post(route('takes.store', TakeFlow::project($customer)), TakeFlow::payload(['parent_take_id' => $othersTake->id]))
        ->assertForbidden();

    expect(UsageRecord::query()->count())->toBe(0);
});

it('records a remix with its lineage', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    $parent = Take::factory()->succeeded()->create(['customer_id' => $customer->id, 'project_id' => $project->id]);

    createTake($customer, $project, [
        'parent_take_id' => $parent->id,
        'reference_images' => ['https://pixelforge.promptedit.dev/images/parent.png'],
    ])->assertSessionHasNoErrors();

    $remix = Take::query()->latest('created_at')->where('parent_take_id', $parent->id)->sole();

    expect($remix->source)->toBe(TakeSource::Remix)
        ->and($remix->reference_images)->toBe(['https://pixelforge.promptedit.dev/images/parent.png'])
        // One reference adds 765 input image tokens to the hold.
        ->and(UsageRecord::query()->where('billable_id', $remix->id)->sole()->max_charge_nanos)
        ->toBe(TakeFlow::HOLD + 765 * 10_000 * 125 / 100);
});

it('replays a deterministic key from an outer transaction (spec 8 apply)', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    $dto = CreateTakeDTO::fromArray(TakeFlow::payload(['idempotency_key' => (string) Str::uuid()]));

    // Pricing over HTTP is never fetched inside a transaction (M-16).
    Http::fake(fn () => throw new RuntimeException('No HTTP inside a transaction.'));

    $takes = DB::transaction(fn (): array => [
        app(CreateTakeAction::class)->execute($customer, $project, $dto),
        app(CreateTakeAction::class)->execute($customer, $project, $dto),
    ]);

    expect($takes[0]->id)->toBe($takes[1]->id)
        ->and(UsageRecord::query()->count())->toBe(1);
    Queue::assertPushed(SubmitTakeJob::class, 1);
});

it('validates the prompt in Unicode characters, the references and the key', function (): void {
    $customer = TakeFlow::customer();

    createTake($customer, overrides: ['prompt' => str_repeat('🌊', 4000)])->assertSessionHasNoErrors();
    createTake($customer, overrides: ['prompt' => str_repeat('é', 4001)])->assertSessionHasErrors('prompt');
    createTake($customer, overrides: ['reference_images' => ['ftp://images.example.com/a.png']])
        ->assertSessionHasErrors(['reference_images.0' => __('takes.composer.reference_invalid')]);
    createTake($customer, overrides: ['reference_images' => array_fill(0, 5, 'https://example.com/a.png')])
        ->assertSessionHasErrors('reference_images');
    createTake($customer, overrides: ['idempotency_key' => 'not-a-uuid'])->assertSessionHasErrors('idempotency_key');

    expect(Take::query()->count())->toBe(1);
});

it('refuses to create a take while the image service is not configured', function (): void {
    config(['pixelforge.api_key' => null]);
    $customer = TakeFlow::customer();

    createTake($customer)->assertSessionHasErrors(['prompt' => __('takes.service.not_configured')]);

    expect(Take::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('throttles creates at 10 a minute per customer with rate_limited', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);

    foreach (range(1, 10) as $attempt) {
        test()->actingAs($customer, 'customer')->postJson(route('takes.store', $project), TakeFlow::payload())->assertRedirect();
    }

    test()->actingAs($customer, 'customer')
        ->postJson(route('takes.store', $project), TakeFlow::payload())
        ->assertStatus(429)
        ->assertJson(['code' => DenialReason::RateLimited->value, 'message' => DenialReason::RateLimited->message()]);
});

it('refuses a project archived after the request was authorized, under the customer lock', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    app(ArchiveProjectAction::class)->execute($project);

    expect(fn () => app(CreateTakeAction::class)->execute($customer, $project->refresh(), CreateTakeDTO::fromArray(TakeFlow::payload())))
        ->toThrow(ProjectArchivedException::class);

    expect(Take::query()->count())->toBe(0)
        ->and(UsageRecord::query()->count())->toBe(0)
        ->and($customer->refresh()->held_nanos)->toBe(0);
    Queue::assertNothingPushed();
});

it('answers 422 project_archived for an archived project, after replaying a key whose take exists (D-033, #140)', function (): void {
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    $payload = TakeFlow::payload();

    createTake($customer, $project, $payload)->assertSessionHasNoErrors();
    app(ArchiveProjectAction::class)->execute($project);

    // The replay returns its take, even though the project was archived since.
    test()->actingAs($customer, 'customer')->postJson(route('takes.store', $project), $payload)->assertRedirect();

    // A new key is refused under the lock with the 2-01 reason, not a bare 403.
    test()->actingAs($customer, 'customer')->postJson(route('takes.store', $project), TakeFlow::payload())
        ->assertUnprocessable()
        ->assertExactJson(['code' => ProjectArchivedException::CODE, 'message' => __('takes.projects.archived')]);

    expect(Take::query()->count())->toBe(1)
        ->and(UsageRecord::query()->count())->toBe(1);
});

it('re-issues a remix reference to an earlier take image, and refuses one that expired', function (): void {
    Storage::fake();
    $customer = TakeFlow::customer();
    $project = TakeFlow::project($customer);
    $parent = Take::factory()->succeeded()->create(['customer_id' => $customer->id, 'project_id' => $project->id]);
    $parent->forceFill([
        'image_path' => "takes/{$customer->id}/{$parent->id}.png",
        'image_url' => "https://pixelforge.promptedit.dev/images/{$parent->pixelforge_id}.png?expires=1&signature=x",
        'image_url_expires_at' => now()->addMinutes(90),
    ])->save();
    Storage::put($parent->image_path, 'png');
    $issued = TakeImageUrl::forReference($parent);

    // Remix 61 minutes later: the stored temporary URL has expired.
    $this->travel(61)->minutes();
    createTake($customer, $project, ['parent_take_id' => $parent->id, 'reference_images' => [$issued]])
        ->assertSessionHasNoErrors();

    $remix = Take::query()->where('parent_take_id', $parent->id)->sole();
    expect($remix->reference_images[0])->not->toBe($issued)
        ->and($remix->reference_images[0])->toBe(TakeImageUrl::forReference($parent));

    // No persisted copy and the provider URL expired: refused, nothing held.
    $parent->forceFill(['image_path' => null, 'image_url_expires_at' => now()->subMinute()])->save();
    $held = $customer->refresh()->held_nanos;

    createTake($customer, $project, ['parent_take_id' => $parent->id, 'reference_images' => [$parent->image_url]])
        ->assertSessionHasErrors(['reference_images' => __('takes.image_unavailable')]);

    expect(Take::query()->where('parent_take_id', $parent->id)->count())->toBe(1)
        ->and($customer->refresh()->held_nanos)->toBe($held);
});
