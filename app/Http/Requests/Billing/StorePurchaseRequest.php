<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * POST /billing/purchases (spec 3-03 issue 6): a pack from
 * `takes.purchase_packs_usd` and a client-minted uuid idempotency key.
 */
final class StorePurchaseRequest extends FormRequest
{
    /**
     * Permission `credits.purchase` (spec 3-01). Resolved through the Gate, so
     * spatie/laravel-permission answers it once 3-01 installs it; until then
     * the ability is undefined and the request is denied (fails closed).
     */
    public function authorize(): bool
    {
        return $this->customer()->can('credits.purchase');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'package' => ['required', 'integer', Rule::in(config('takes.purchase_packs_usd'))],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** The authenticated customer (customer guard, D-012/D-032). */
    public function customer(): Customer
    {
        $customer = $this->user('customer');

        if (! $customer instanceof Customer) {
            throw new LogicException('POST /billing/purchases must sit behind auth:customer.');
        }

        return $customer;
    }
}
