<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Http\Requests\CustomerFormRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ProfileUpdateRequest extends CustomerFormRequest
{
    use ProfileValidationRules;

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (config('fortify.lowercase_usernames') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

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
        return $this->profileRules($this->customer()->id);
    }
}
