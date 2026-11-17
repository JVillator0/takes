<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Http\Requests\CustomerFormRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

final class PasswordUpdateRequest extends CustomerFormRequest
{
    use PasswordValidationRules;

    /** Shared demo logins cannot change their account (D-072); the action re-checks. */
    public function authorize(): Response
    {
        return Gate::inspect('updateAccount', $this->customer());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
