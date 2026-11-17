<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    private static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_demo' => false,
            'balance_nanos' => 0,
            'held_nanos' => 0,
        ];
    }

    /** An ephemeral "Try a demo account" customer (D-045, D-065). */
    public function demo(): static
    {
        return $this->state(fn (): array => [
            'is_demo' => true,
            'demo_expires_at' => now()->addHours(24),
        ]);
    }

    /**
     * Sets only the cached columns, without ledger entries or usage records,
     * so billing:verify would flag it. Use the billing actions for real money.
     */
    public function withCachedBalance(int $balanceNanos, int $heldNanos = 0): static
    {
        return $this->state(fn (): array => [
            'balance_nanos' => $balanceNanos,
            'held_nanos' => $heldNanos,
        ]);
    }

    /**
     * Assigns a customer-guard role (spec 3-01). The roles must exist: seed
     * RolesAndPermissionsSeeder first.
     */
    public function withRole(CustomerRole $role = CustomerRole::Customer): static
    {
        return $this->afterCreating(fn (Customer $customer) => $customer->assignRole($role->value));
    }

    /** An operator-restricted account: may buy credits and manage projects only. */
    public function restricted(): static
    {
        return $this->withRole(CustomerRole::Restricted);
    }
}
