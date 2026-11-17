<?php

declare(strict_types=1);

namespace App\Support\Suggestions;

use App\Ai\Agents\TakeChangeAgent;
use App\DTOs\Suggestions\TakeChangeInputDTO;
use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Exceptions\Suggestions\InvalidProposalException;
use App\Support\Pricing\PixelforgeTokenTable;
use App\Support\Takes\TakeParameterRules;
use Illuminate\Support\Facades\Validator;

/**
 * Re-validates the model's structured output server-side (spec 8: "validated
 * with the StoreTakeRequest rules; invalid ⇒ failed + void"). The schema sent
 * to the model is a request, not a guarantee: this is the guarantee. Exactly
 * the six proposal keys, nothing else (no URLs, no extra parameters), the
 * take parameter rules, strict booleans, and at most four references.
 */
final readonly class TakeChangeProposalParser
{
    private const array KEYS = ['prompt', 'size', 'quality', 'keep_references', 'add_base_image_as_reference', 'rationale'];

    /**
     * @param  array<array-key, mixed>  $output  the decoded structured output
     *
     * @throws InvalidProposalException
     */
    public function parse(array $output, TakeChangeInputDTO $base): TakeChangeProposalDTO
    {
        $keys = array_map(strval(...), array_keys($output));
        $unexpected = array_diff($keys, self::KEYS);
        $missing = array_diff(self::KEYS, $keys);

        if ($unexpected !== [] || $missing !== []) {
            throw InvalidProposalException::because(sprintf(
                'unexpected keys [%s], missing keys [%s]',
                implode(', ', $unexpected),
                implode(', ', $missing),
            ));
        }

        $validator = Validator::make($output, [
            ...TakeParameterRules::rules(),
            'rationale' => ['required', 'string', 'max:'.TakeChangeAgent::MAX_RATIONALE_CHARACTERS],
        ]);

        if ($validator->fails()) {
            throw InvalidProposalException::because(implode(' ', $validator->errors()->all()));
        }

        // Strict booleans: "true", 1 or "yes" are not booleans in a schema-bound output.
        if (! is_bool($output['keep_references']) || ! is_bool($output['add_base_image_as_reference'])) {
            throw InvalidProposalException::because('reference flags must be booleans');
        }

        /** @var array{prompt: string, size: string, quality: string, keep_references: bool, add_base_image_as_reference: bool, rationale: string} $output */
        $proposal = TakeChangeProposalDTO::fromArray($output);

        if ($proposal->referenceCount($base->referenceCount) > PixelforgeTokenTable::MAX_REFERENCE_IMAGES) {
            throw InvalidProposalException::because('too many reference images');
        }

        return $proposal;
    }
}
