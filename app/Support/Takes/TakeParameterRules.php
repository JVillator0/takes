<?php

declare(strict_types=1);

namespace App\Support\Takes;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Support\Pricing\PixelforgeTokenTable;
use Illuminate\Validation\Rule;

/**
 * The validation rules of a take's parameters (spec 3-02 CHECKs, spec 4
 * "Create take"). One list for every way a take's parameters enter the app:
 * spec 4's StoreTakeRequest and the AI proposal re-validation (spec 8), so a
 * model output can never carry a value the composer would reject.
 */
final class TakeParameterRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            // `max` counts Unicode characters (mb_strlen), like the char_length CHECK.
            'prompt' => ['required', 'string', 'max:'.PixelforgeTokenTable::MAX_PROMPT_CHARACTERS],
            'size' => ['required', 'string', Rule::enum(TakeSize::class)],
            'quality' => ['required', 'string', Rule::enum(TakeQuality::class)],
        ];
    }
}
