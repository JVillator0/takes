<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\ChannelAuthBroadcaster;

/*
| Private channel `App.Models.Customer.{id}` (spec 3-01): authorized on the
| customer guard only. Reverb itself is spec 5.
*/

beforeEach(function (): void {
    Broadcast::extend('channel-auth', fn (): ChannelAuthBroadcaster => new ChannelAuthBroadcaster);
    config([
        'broadcasting.default' => 'channel-auth',
        'broadcasting.connections.channel-auth' => ['driver' => 'channel-auth'],
    ]);

    // Register the app's channels on the test driver.
    require base_path('routes/channels.php');
});

function authorizeChannel(int $customerId): array
{
    return ['channel_name' => "private-App.Models.Customer.{$customerId}", 'socket_id' => '1234.5678'];
}

it('authorizes a customer on their own channel', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->postJson('/broadcasting/auth', authorizeChannel($customer->id))
        ->assertOk()
        ->assertExactJson(['authorized' => true]);
});

it('forbids another customer\'s channel', function (): void {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->postJson('/broadcasting/auth', authorizeChannel($other->id))
        ->assertForbidden();
});

it('rejects an admin session', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs(User::factory()->create(['id' => $customer->id]), 'web')
        ->postJson('/broadcasting/auth', authorizeChannel($customer->id))
        ->assertUnauthorized();
});

it('rejects a guest', function (): void {
    $this->postJson('/broadcasting/auth', authorizeChannel(1))->assertUnauthorized();
});
