<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Enums\Takes\TakeSource;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A take to create (spec 4 "Create take"). The idempotency key is minted by
 * the composer per draft (D-033) or, for an AI apply, derived from the
 * suggestion id (spec 8), so a replay returns the same take.
 */
final readonly class CreateTakeDTO
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $prompt,
        public TakeSize $size,
        public TakeQuality $quality,
        public array $referenceImages,
        public string $idempotencyKey,
        public ?string $parentTakeId = null,
        public ?TakeSource $source = null,
        public ?string $suggestionId = null,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>|null, idempotency_key: string, parent_take_id?: string|null, source?: string|null, suggestion_id?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            prompt: $data['prompt'],
            size: TakeSize::from($data['size']),
            quality: TakeQuality::from($data['quality']),
            referenceImages: array_values($data['reference_images'] ?? []),
            idempotencyKey: $data['idempotency_key'],
            parentTakeId: $data['parent_take_id'] ?? null,
            source: isset($data['source']) ? TakeSource::from($data['source']) : null,
            suggestionId: $data['suggestion_id'] ?? null,
        );
    }

    public static function fromRequest(FormRequest $request): self
    {
        /** @var list<string> $references */
        $references = array_values(array_filter(
            (array) $request->input('reference_images', []),
            fn (mixed $url): bool => is_string($url) && trim($url) !== '',
        ));

        $parent = $request->input('parent_take_id');

        return new self(
            prompt: $request->string('prompt')->toString(),
            size: $request->enum('size', TakeSize::class) ?? TakeSize::Square,
            quality: $request->enum('quality', TakeQuality::class) ?? TakeQuality::Medium,
            referenceImages: array_map(trim(...), $references),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            parentTakeId: is_string($parent) && $parent !== '' ? $parent : null,
        );
    }

    /** `remix` when the composer was filled from a parent take, `composer` otherwise. */
    public function resolvedSource(): TakeSource
    {
        return $this->source ?? ($this->parentTakeId !== null ? TakeSource::Remix : TakeSource::Composer);
    }
}
