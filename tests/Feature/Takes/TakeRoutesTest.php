<?php

declare(strict_types=1);

use App\Enums\Takes\FailureCode;
use App\Enums\Takes\SubmitErrorKind;
use App\Models\Take;
use App\Support\Takes\TakeImageUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProviderLeak;
use Tests\Support\TakeFlow;
use Tests\Support\Takes;

/*
| `GET /takes/{take}` and `GET /takes/{take}/image` (spec 4): owner only, the
| same 404 for a stranger and an unknown id; money as server strings (M-20,
| D-042); prices only, never provider data (M-23, D-023).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    Queue::fake();
    Storage::fake();
});

it('formats money as strings: 4 decimals on the card, exact in the drawer', function (string $status, string $line, ?string $exact, string $amount): void {
    $take = Takes::in($status);

    $data = $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->getJson(route('takes.show', $take))
        ->assertOk()
        ->json('data');

    expect($data['moneyLine'])->toBe($line)
        ->and($data['moneyExact'])->toBe($exact)
        ->and($data['price']['amount'])->toBe($amount)
        // Default hold: the docs' worked example, max cost 373,875,000 × 1.25 (D-042 exact).
        ->and($data['price']['estimateLine'])->toBe('estimate was $0.00 – $0.46734375');
})->with([
    'pending' => ['pending', 'Up to $0.4674 on hold', '$0.46734375', '$0.46734375'],
    'queued' => ['queued', 'Up to $0.4674 on hold', '$0.46734375', '$0.46734375'],
    'succeeded' => ['succeeded', 'Charged $0.3819', '$0.3818875', '$0.3818875'],
    'failed' => ['failed', 'Not charged', null, '$0.00'],
]);

it('says why a pending take waits and offers Cancel only while waiting for capacity', function (?SubmitErrorKind $kind, string $phase, bool $canCancel): void {
    $take = Takes::pendingWithHold(['last_submit_error_kind' => $kind, 'last_submit_error' => $kind ? 'overloaded' : null]);

    $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->getJson(route('takes.show', $take))
        ->assertJsonPath('data.phase', $phase)
        ->assertJsonPath('data.canCancel', $canCancel);
})->with([
    'first attempt' => [null, 'submitting', false],
    'capacity' => [SubmitErrorKind::Capacity, 'waiting_for_capacity', true],
    'ambiguous' => [SubmitErrorKind::Ambiguous, 'submitting', false],
]);

it('hides Try again for content policy and internal failures only', function (string $code, bool $canRetry): void {
    $take = Take::factory()->failed(FailureCode::from($code))->create();

    $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->getJson(route('takes.show', $take))
        ->assertJsonPath('data.canRetry', $canRetry)
        ->assertJsonPath('data.failureMessage', __("takes.failure.{$code}"));
})->with([
    ['content_policy', false],
    ['internal', false],
    ['generation_failed', true],
    ['internal_error', true],
    ['provider_busy', true],
    ['submit_timeout', true],
    ['cancelled', true],
    ['daily_limit', true],
    ['validation', true],
]);

it('numbers takes per project and shows the lineage', function (): void {
    $parent = Takes::in('succeeded');
    $remix = Take::factory()->remixOf($parent)->create(['created_at' => now()->addSecond()]);

    $data = $this->actingAs($parent->customer()->firstOrFail(), 'customer')
        ->getJson(route('takes.show', $remix))
        ->json('data');

    expect($data['number'])->toBe(2)
        ->and($data['parentId'])->toBe($parent->id)
        ->and($data['parentNumber'])->toBe(1)
        ->and($data['parent']['number'])->toBe(1)
        ->and(array_column($data['versions'], 'number'))->toBe([1, 2])
        ->and($data['paramsLine'])->toContain('Remix of #1');
});

/**
 * A succeeded take of the same customer whose image a reference points at,
 * with its persisted copy when asked.
 */
function referencedTake(Take $take, bool $persisted): Take
{
    $source = Take::factory()->succeeded()->create([
        'customer_id' => $take->customer_id,
        'project_id' => $take->project_id,
        'image_url_expires_at' => now()->addMinutes(30),
    ]);

    if ($persisted) {
        $source->forceFill(['image_path' => "takes/{$take->customer_id}/{$source->id}.png"])->save();
        Storage::put((string) $source->image_path, 'png');
    }

    return $source;
}

/** The provider's signed URL of a take image, the way a remix stored it. */
function providerReference(Take $source): string
{
    return 'https://'.config()->string('pixelforge.image_host')."/images/{$source->pixelforge_id}.png?expires=1&signature=x";
}

it('never exposes provider data', function (string $status): void {
    $take = Takes::in($status);
    // A reference to another take image, stored as the provider's URL, and
    // one of the customer's own: the scan reaches referenceImages (#140).
    $take->forceFill(['reference_images' => [
        providerReference(referencedTake($take, persisted: true)),
        'https://images.example/ref-1.png',
    ]])->save();

    $data = $this->actingAs($take->customer()->firstOrFail(), 'customer')
        ->getJson(route('takes.show', $take))
        ->json('data');

    expect(ProviderLeak::find($data))->toBe([]);
})->with(['pending', 'queued', 'processing', 'succeeded', 'failed']);

it('is the same 404 for another customer and an unknown take', function (string $route): void {
    $take = Takes::in('succeeded');
    $stranger = TakeFlow::customer();

    $foreign = $this->actingAs($stranger, 'customer')->getJson(route($route, $take));
    $unknown = $this->actingAs($stranger, 'customer')->getJson(route($route, ['take' => '01jc2z8q4n6v0m3h7x5t9r2b1k']));

    $foreign->assertNotFound();
    $unknown->assertNotFound();
    expect($foreign->json('message'))->toBe($unknown->json('message'));
})->with(['takes.show', 'takes.image']);

it('streams the persisted image to its owner only', function (): void {
    $take = Takes::in('succeeded');
    $path = "takes/{$take->customer_id}/{$take->id}.png";
    Storage::put($path, "\x89PNG\r\n\x1a\nimage");
    $take->forceFill(['image_path' => $path])->save();
    $owner = $take->customer()->firstOrFail();

    $this->actingAs($owner, 'customer')->get(route('takes.image', $take))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->actingAs($owner, 'customer')->get(route('takes.image', ['take' => $take, 'download' => 1]))
        ->assertOk()
        ->assertDownload('take-1.png');

    $this->actingAs(TakeFlow::customer(), 'customer')->get(route('takes.image', $take))->assertNotFound();
});

it('is 404 when the image was never persisted', function (): void {
    $take = Takes::in('succeeded');

    $this->actingAs($take->customer()->firstOrFail(), 'customer')->get(route('takes.image', $take))->assertNotFound();
});

it('serves a persisted image as a temporary URL, else the provider URL while valid', function (): void {
    $take = Takes::in('succeeded');
    $take->forceFill(['image_url_expires_at' => now()->addHour()])->save();
    $owner = $take->customer()->firstOrFail();

    expect($this->actingAs($owner, 'customer')->getJson(route('takes.show', $take))->json('data.imageUrl'))
        ->toBe($take->image_url);

    $take->forceFill(['image_path' => "takes/{$take->customer_id}/{$take->id}.png"])->save();
    Storage::put($take->image_path, 'png');

    expect($this->actingAs($owner, 'customer')->getJson(route('takes.show', $take))->json('data.imageUrl'))
        ->not->toBe($take->image_url)->not->toBeNull();
});

it('drops an expired provider URL with no persisted copy (image no longer available)', function (): void {
    $take = Takes::in('succeeded');
    $take->forceFill(['image_url_expires_at' => now()->subMinute()])->save();

    $data = $this->actingAs($take->customer()->firstOrFail(), 'customer')->getJson(route('takes.show', $take))->json('data');

    expect($data['imageUrl'])->toBeNull()
        ->and($data['remixReferenceUrl'])->toBeNull();
});

it('re-issues take-image references for the drawer and drops the ones that no longer resolve (#140)', function (): void {
    $take = Takes::in('succeeded');
    $persisted = referencedTake($take, persisted: true);
    $expiring = referencedTake($take, persisted: false);
    $issued = TakeImageUrl::forReference($persisted);
    $take->forceFill(['reference_images' => [$issued, providerReference($expiring), 'https://images.example/ref-1.png']])->save();

    // The 60-minute URL issued at create time and the provider URL have both expired.
    $this->travel(61)->minutes();

    $data = $this->actingAs($take->customer()->firstOrFail(), 'customer')->getJson(route('takes.show', $take))->json('data');

    expect($data['referenceImages'])->toBe([TakeImageUrl::forReference($persisted), 'https://images.example/ref-1.png'])
        ->and($data['referenceImages'][0])->not->toBe($issued)
        // The parameters line still counts what the take was created with.
        ->and($data['paramsLine'])->toContain('3 references');
});
