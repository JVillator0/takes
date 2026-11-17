<?php

declare(strict_types=1);

use App\Actions\Takes\CreateTakeAction;
use App\DTOs\Takes\CreateTakeDTO;
use App\Services\Pixelforge\PixelforgeClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixture;
use Tests\Support\TakeFlow;

/*
| Spec 4 "Create take" step 2 prices the real prompt with fresh GET
| /v1/pricing facts, fetched before the transaction opens (M-16). Needs a
| real transaction level of 0, so it lives outside the RefreshDatabase suite.
*/

it('fetches pricing outside any transaction, then holds the max', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    Queue::fake();
    Cache::forget(PixelforgeClient::PRICING_CACHE_KEY);
    Http::preventStrayRequests();
    Http::fake([TakeFlow::API.'/pricing' => function (Request $request) {
        expect(DB::transactionLevel())->toBe(0);

        return Http::response(PixelforgeFixture::json('pricing'));
    }]);

    $customer = TakeFlow::customer();
    $take = app(CreateTakeAction::class)->execute($customer, TakeFlow::project($customer), CreateTakeDTO::fromArray(TakeFlow::payload()));

    Http::assertSentCount(1);
    expect($customer->refresh()->held_nanos)->toBe(TakeFlow::HOLD)
        ->and($take->exists)->toBeTrue();
});
