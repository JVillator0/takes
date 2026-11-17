<?php

declare(strict_types=1);

namespace App\DTOs\Suggestions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A "Describe a change" request (spec 8 step 2). `requestKey` is minted by
 * the client per draft (D-033 pattern): a replay returns the existing
 * suggestion with no second call and no second hold.
 */
final readonly class CreateSuggestionDTO
{
    public function __construct(
        public string $instruction,
        public string $requestKey,
    ) {}

    /**
     * @param  array{instruction: string, request_key: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['instruction'], $data['request_key']);
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            trim($request->string('instruction')->toString()),
            $request->string('request_key')->lower()->toString(),
        );
    }
}
