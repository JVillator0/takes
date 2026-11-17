<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * GET /billing (spec 6, P08): the signed-in customer's own billing page, so
 * no ability beyond the `customer` route group (D-032). `page` is the
 * Activity page.
 */
final class ShowBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function customer(): Customer
    {
        $customer = $this->user('customer');

        if (! $customer instanceof Customer) {
            throw new LogicException('GET /billing must sit behind the customer route group.');
        }

        return $customer;
    }
}
