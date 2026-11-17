<?php

declare(strict_types=1);

use App\Actions\Takes\PersistTakeImageAction;
use App\Enums\Takes\ImagePersistOutcome;
use App\Jobs\PersistTakeImageJob;
use App\Models\Take;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TakeFlow;

/*
| Spec 4 "Persist image" (D-020, D-067): the PNG is copied to the private
| default disk under takes/{customer}/{take}.png, only from the image host,
| only when it is a PNG under 20 MB. Failure is non-fatal.
*/

const IMAGE_URL = 'https://pixelforge.promptedit.dev/images/gen_01jc2z8q4n6v0m3h7x5t9r2b1k.png?expires=1&signature=x';

beforeEach(function (): void {
    TakeFlow::configure();
    Storage::fake();
    Http::preventStrayRequests();
});

function png(int $bytes = 64): string
{
    return "\x89PNG\r\n\x1a\n".str_repeat("\0", max(0, $bytes - 8));
}

function succeededTake(string $url = IMAGE_URL): Take
{
    return Take::factory()->succeeded()->create(['image_url' => $url]);
}

it('stores the PNG on the default disk and records the path', function (): void {
    $take = succeededTake();
    Http::fake([IMAGE_URL => Http::response(png(), 200, ['Content-Type' => 'image/png'])]);

    expect(app(PersistTakeImageAction::class)->execute($take))->toBe(ImagePersistOutcome::Stored);

    $path = "takes/{$take->customer_id}/{$take->id}.png";
    Storage::assertExists($path);
    expect(Storage::get($path))->toBe(png())
        ->and($take->refresh()->image_path)->toBe($path);
});

it('downloads once: a second run keeps the stored copy', function (): void {
    $take = succeededTake();
    Http::fake([IMAGE_URL => Http::response(png(), 200, ['Content-Type' => 'image/png'])]);

    app(PersistTakeImageAction::class)->execute($take);

    expect(app(PersistTakeImageAction::class)->execute($take))->toBe(ImagePersistOutcome::AlreadyStored);
    Http::assertSentCount(1);
});

it('refuses another host without a request (SSRF guard)', function (string $url): void {
    $take = succeededTake($url);

    expect(app(PersistTakeImageAction::class)->execute($take))->toBe(ImagePersistOutcome::WrongHost)
        ->and($take->refresh()->image_path)->toBeNull();
    Http::assertNothingSent();
})->with([
    'another host' => 'https://evil.example.com/a.png',
    'a lookalike host' => 'https://pixelforge.promptedit.dev.evil.example.com/a.png',
    'plain http' => 'http://pixelforge.promptedit.dev/images/a.png',
    'internal address' => 'https://169.254.169.254/latest/meta-data',
]);

it('refuses a response that is not a PNG', function (string $contentType, string $body): void {
    $take = succeededTake();
    Http::fake([IMAGE_URL => Http::response($body, 200, ['Content-Type' => $contentType])]);

    expect(app(PersistTakeImageAction::class)->execute($take))->toBe(ImagePersistOutcome::NotPng)
        ->and($take->refresh()->image_path)->toBeNull();
    Storage::assertDirectoryEmpty('/');
})->with([
    'html content type' => ['text/html', '<html></html>'],
    'png content type, jpeg bytes' => ['image/png', "\xFF\xD8\xFF\xE0".str_repeat('x', 60)],
]);

it('refuses an image over the size limit while streaming', function (): void {
    config(['pixelforge.images.max_bytes' => 1024]);
    $take = succeededTake();
    Http::fake([IMAGE_URL => Http::response(png(4096), 200, ['Content-Type' => 'image/png'])]);

    expect(app(PersistTakeImageAction::class)->execute($take))->toBe(ImagePersistOutcome::TooLarge)
        ->and($take->refresh()->image_path)->toBeNull();
});

it('throws on a network failure so the job retries', function (): void {
    $take = succeededTake();
    Http::fake([IMAGE_URL => Http::failedConnection()]);

    app(PersistTakeImageAction::class)->execute($take);
})->throws(ConnectionException::class);

it('retries after 30 s, 5 min and 15 min, until 23 h after completion', function (): void {
    $take = succeededTake();
    $job = new PersistTakeImageJob($take->id);

    expect($job->backoff())->toBe([30, 300, 900])
        ->and($job->retryUntil()->getTimestamp())->toBe($take->completed_at->addHours(23)->getTimestamp());
});

it('does nothing for a take that has no image', function (): void {
    $take = Take::factory()->failed()->create();

    (new PersistTakeImageJob($take->id))->handle(app(PersistTakeImageAction::class));

    expect($take->refresh()->image_path)->toBeNull();
    Http::assertNothingSent();
});

it('gives up quietly on an expired URL (4xx) and retries a 5xx', function (): void {
    $take = succeededTake();
    Http::fake([IMAGE_URL => Http::sequence()->push('gone', 404)->push('oops', 503)]);

    expect(app(PersistTakeImageAction::class)->execute($take))->toBe(ImagePersistOutcome::Unavailable);

    app(PersistTakeImageAction::class)->execute($take);
})->throws(RequestException::class);

it('retries a rate-limited or timed-out download instead of giving up', function (int $status): void {
    $take = succeededTake();
    Http::fake([IMAGE_URL => Http::response('slow down', $status)]);

    expect(fn () => app(PersistTakeImageAction::class)->execute($take))
        ->toThrow(RequestException::class);

    expect($take->refresh()->image_path)->toBeNull();
})->with([408, 429]);
