<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for requests in the customer area (D-032): `customer.guard` made the
 * customer guard the request default, so `user()` is the Customer.
 */
abstract class CustomerFormRequest extends FormRequest
{
    /** @throws AuthenticationException when the request has no customer (never on a `customer` route). */
    public function customer(): Customer
    {
        $customer = $this->user();

        if (! $customer instanceof Customer) {
            throw new AuthenticationException(guards: ['customer']);
        }

        return $customer;
    }
}
