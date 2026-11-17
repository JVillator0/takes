<?php

declare(strict_types=1);

use App\DTOs\Pixelforge\CreateGenerationDTO;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Models\Take;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
});

it('shares pixelforge.configured = false and never creates a generation without a key', function (?string $key): void {
    config(['pixelforge.api_key' => $key]);

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('pixelforge', fn (AssertableInertia $pixelforge) => $pixelforge->where('configured', false)));

    expect(fn () => app(PixelforgeClient::class)->createGeneration(CreateGenerationDTO::forTake(Take::factory()->create()), 'key'))
        ->toThrow(PixelforgeNotConfigured::class);

    Http::assertNothingSent();
})->with([
    'unset' => [null],
    'empty' => [''],
    'blank' => ['   '],
]);

it('shares pixelforge.configured = true with a key, and only the boolean', function (): void {
    config(['pixelforge.api_key' => 'pf_live_should_never_reach_the_browser']);

    $response = $this->get('/');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('pixelforge', fn (AssertableInertia $pixelforge) => $pixelforge->where('configured', true)));

    expect($response->getContent())->not->toContain('pf_live_should_never_reach_the_browser');
});
