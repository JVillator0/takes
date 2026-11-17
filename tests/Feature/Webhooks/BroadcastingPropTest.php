<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

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
