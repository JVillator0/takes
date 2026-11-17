<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Facades\Broadcast;

/*
| Private broadcast channels (spec 3-01; Reverb and the TakeUpdated payload
| are spec 5). Authorization is owned here: a customer may only listen to
| their own channel, resolved on the `customer` guard, never `web`.
*/

Broadcast::channel(
    'App.Models.Customer.{id}',
    fn (Customer $customer, string $id): bool => $customer->id === (int) $id,
    ['guards' => ['customer']],
);
