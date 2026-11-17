<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Auth\RegisterCustomerAction;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\DTOs\Auth\RegisterCustomerDTO;
use App\Models\Customer;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration hook. Fortify authenticates on the `customer` guard,
 * so a registration always creates a Customer, never an admin User (D-012).
 */
final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly RegisterCustomerAction $registerCustomer) {}

    /**
     * Validate and create a newly registered customer.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): Customer
    {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->registerCustomer->execute(RegisterCustomerDTO::fromArray($validated));
    }
}
