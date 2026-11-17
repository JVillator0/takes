<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * Writes `customers.last_active_at` (Customers › Last active, spec 7) at most every five minutes: one conditional
 * UPDATE, never under the money lock and without touching `updated_at`.
 */
final readonly class TouchCustomerLastActiveAction
{
    public const int EVERY_MINUTES = 5;

    public function execute(Customer $customer): void
    {
        $now = CarbonImmutable::now();

        if ($customer->last_active_at !== null && $customer->last_active_at->greaterThan($now->subMinutes(self::EVERY_MINUTES))) {
            return;
        }

        Customer::query()
            ->whereKey($customer->id)
            ->where(fn ($query) => $query->whereNull('last_active_at')->orWhere('last_active_at', '<=', $now->subMinutes(self::EVERY_MINUTES)))
            ->toBase()
            ->update(['last_active_at' => $now]);

        $customer->last_active_at = $now;
        $customer->syncOriginalAttribute('last_active_at');
    }
}
