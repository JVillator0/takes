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
            'instruction' => ['required', 'string', 'max:'.GetSuggestionQuoteQuery::MAX_INSTRUCTION_CHARACTERS],
            'request_key' => ['required', 'uuid'],
        ];
    }
}
