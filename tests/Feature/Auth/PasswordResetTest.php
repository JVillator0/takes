<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('renders the forgot-password page', function (): void {
    $this->get(route('password.request'))->assertOk();
});

it('sends the reset link through the customers broker and its own token table', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();

    $this->post(route('password.email'), ['email' => $customer->email]);

    Notification::assertSentTo($customer, ResetPassword::class);
    expect(DB::table('customer_password_reset_tokens')->where('email', $customer->email)->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('renders the reset page and resets the password with a valid token', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();

    $this->post(route('password.email'), ['email' => $customer->email]);

    Notification::assertSentTo($customer, ResetPassword::class, function (ResetPassword $notification) use ($customer): bool {
        $this->get(route('password.reset', $notification->token))->assertOk();

        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $customer->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return Hash::check('new-password', $customer->refresh()->password);
    });
});

it('rejects an invalid token', function (): void {
    $customer = Customer::factory()->create();

    $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $customer->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasErrors('email');
});

it('keeps admin and customer resets isolated for the same email', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['email' => 'same@example.com']);
    $customer = Customer::factory()->create(['email' => 'same@example.com']);

    $adminToken = Password::broker('users')->createToken($admin);
    $this->post(route('password.email'), ['email' => 'same@example.com']);

    Notification::assertNotSentTo($admin, ResetPassword::class);
    Notification::assertSentTo($customer, ResetPassword::class, function (ResetPassword $notification): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => 'same@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    // The admin's token and password are untouched; the customer's token was consumed.
    expect(Password::broker('users')->tokenExists($admin, $adminToken))->toBeTrue()
        ->and(Hash::check('password', $admin->refresh()->password))->toBeTrue()
        ->and(DB::table('customer_password_reset_tokens')->count())->toBe(0)
        ->and(Hash::check('new-password', $customer->refresh()->password))->toBeTrue();
});
