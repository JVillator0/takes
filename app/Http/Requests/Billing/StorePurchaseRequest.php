<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Customer;
use App\Models\Purchase;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * POST /billing/purchases (spec 3-03 issue 6): a pack from
 * `takes.purchase_packs_usd` and a client-minted uuid idempotency key.
 */
final class StorePurchaseRequest extends FormRequest
{
    /**
     * PurchasePolicy::create (`credits.purchase`, spec 3-01): the same answer
     * as the shared `auth.can.purchaseCredits`. A denial carries its
     * DenialReason, so the page gets the 2-01 copy, not a bare 403.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->customer())->inspect('create', Purchase::class);
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
