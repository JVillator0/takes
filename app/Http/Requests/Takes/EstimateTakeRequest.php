<?php

declare(strict_types=1);

namespace App\Http\Requests\Takes;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Http\Requests\CustomerFormRequest;
use App\Support\Pricing\PixelforgeTokenTable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * `GET /estimates/take?chars=&size=&quality=&references=` (spec 4
 * "Estimate"). The prompt text is never sent for estimating: only its
 * Unicode character count. A `prompt` parameter is rejected.
 */
final class EstimateTakeRequest extends CustomerFormRequest
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
            'chars' => ['required', 'integer', 'min:0', 'max:'.PixelforgeTokenTable::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(TakeSize::class)],
            'quality' => ['required', Rule::enum(TakeQuality::class)],
            'references' => ['nullable', 'integer', 'min:0', 'max:'.PixelforgeTokenTable::MAX_REFERENCE_IMAGES],
            'prompt' => ['prohibited'],
        ];
    }
}
