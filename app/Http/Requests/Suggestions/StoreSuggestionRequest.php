<?php

declare(strict_types=1);

namespace App\Http\Requests\Suggestions;

use App\Http\Requests\CustomerFormRequest;
use App\Queries\Suggestions\GetSuggestionQuoteQuery;

/**
 * `POST /takes/{take}/suggestions` (spec 8 step 2): the instruction (≤ 500
 * characters) and the client-minted request key (D-033 pattern).
 */
final class StoreSuggestionRequest extends CustomerFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // `regex` with /u fails on invalid UTF-8, which the JSON message could not encode.
            'instruction' => ['required', 'string', 'max:'.GetSuggestionQuoteQuery::MAX_INSTRUCTION_CHARACTERS, 'regex:/\A[\s\S]*\z/u'],
            'request_key' => ['required', 'uuid'],
        ];
    }
}
