<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Auth\DemoAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The seeded demo addresses are reserved (D-072, #111): demo identity is the
 * email alone, so a customer who registered one before the seeder ran would
 * be adopted with its own role. They read as taken, in any letter case.
 */
final class NotDemoAccountEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && in_array(mb_strtolower(trim($value)), DemoAccount::emails(), true)) {
            $fail('validation.unique')->translate();
        }
    }
}
