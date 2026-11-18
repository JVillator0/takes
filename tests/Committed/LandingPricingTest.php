<?php

declare(strict_types=1);

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TakeFlow;

/*
| P01 landing (#144 #4): the example ranges never call GET /v1/pricing, even
| on a cold cache, so an anonymous visitor never waits on the provider. Needs
| a real transaction level of 0: inside RefreshDatabase the pricing query
| would skip the provider whatever the controller asked for.
*/

it('prices the landing examples without calling the provider on a cold cache', function (): void {
    TakeFlow::configure();
    Cache::forget(PixelforgeClient::PRICING_CACHE_KEY);
    Http::preventStrayRequests();

    expect(DB::transactionLevel())->toBe(0);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pricingExamples.1.range', '$0.3577 – $0.4674'));

    Http::assertNothingSent();
});
