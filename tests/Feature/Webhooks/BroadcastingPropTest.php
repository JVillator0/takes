<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/*
| W-20, D-028: pages listen on the socket only when the server broadcasts
| over Reverb; with `log`/`null` the shared prop is false and they poll.
*/

it('shares whether the server broadcasts over Reverb', function (string $driver, bool $live): void {
    config(['broadcasting.default' => $driver]);

    $props = app(HandleInertiaRequests::class)->share(Request::create('/'));

    expect($props['broadcasting'])->toBe(['live' => $live]);
})->with([
    ['reverb', true],
    ['log', false],
    ['null', false],
]);

it('builds the Reverb broadcaster before Reverb keys are injected', function (): void {
    // package:discover and routes/channels.php resolve the default broadcaster
    // during a build; with BROADCAST_CONNECTION=reverb and no keys yet, that
    // must not fail (the keys default to empty strings).
    config(['broadcasting.default' => 'reverb']);

    expect(config('broadcasting.connections.reverb.key'))->toBeString()
        ->and(Broadcast::connection('reverb'))->toBeInstanceOf(PusherBroadcaster::class);
});
