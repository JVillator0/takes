<?php

declare(strict_types=1);

namespace App\DTOs\Pixelforge;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Models\Take;
use App\Support\Pixelforge\Payload;
use InvalidArgumentException;

/**
 * The body of POST /v1/generations. Metadata always carries our take and
 * customer ids, so every webhook can be matched without a lookup by gen id.
 *
 * @phpstan-type CreateGenerationPayload array{
 *     prompt: string,
 *     size: '1024x1024'|'1536x1024'|'1024x1536',
 *     quality: 'low'|'medium'|'high',
 *     reference_images: list<string>,
 *     metadata: array{app_take_id: string, app_customer_id: string},
 * }
 */
final readonly class CreateGenerationDTO
{
    public const int MAX_PROMPT_CHARACTERS = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $prompt,
        public TakeSize $size,
        public TakeQuality $quality,
        public array $referenceImages,
        public string $appTakeId,
        public string $appCustomerId,
    ) {
        // Unicode characters, as Pixelforge counts them (spec 3-03 §4).
        if ($prompt === '' || mb_strlen($prompt) > self::MAX_PROMPT_CHARACTERS) {
            throw new InvalidArgumentException('A Pixelforge prompt must have 1 to 4000 characters.');
        }

        if (count($referenceImages) > self::MAX_REFERENCE_IMAGES) {
            throw new InvalidArgumentException('Pixelforge accepts at most 4 reference images.');
        }
    }

    public static function forTake(Take $take): self
    {
        return new self(
            prompt: $take->prompt,
            size: $take->size,
            quality: $take->quality,
            referenceImages: $take->reference_images,
            appTakeId: $take->id,
            appCustomerId: (string) $take->customer_id,
        );
    }

    /**
     * @param  array<mixed>  $data  a {@see CreateGenerationPayload}
     */
    public static function fromArray(array $data): self
    {
        $size = TakeSize::tryFrom(Payload::string($data, 'size'))
            ?? throw Payload::invalid('size', 'a supported size');
        $quality = TakeQuality::tryFrom(Payload::string($data, 'quality'))
            ?? throw Payload::invalid('quality', 'a supported quality');
        $metadata = Payload::stringMap($data, 'metadata');

        $referenceImages = [];

        foreach (Payload::array($data, 'reference_images') as $url) {
            if (! is_string($url)) {
                throw Payload::invalid('reference_images', 'a list of URLs');
            }

            $referenceImages[] = $url;
        }

        return new self(
            prompt: Payload::string($data, 'prompt'),
            size: $size,
            quality: $quality,
            referenceImages: $referenceImages,
            appTakeId: Payload::string($metadata, 'app_take_id'),
            appCustomerId: Payload::string($metadata, 'app_customer_id'),
        );
    }

    /**
     * @return CreateGenerationPayload
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->referenceImages,
            'metadata' => [
                'app_take_id' => $this->appTakeId,
                'app_customer_id' => $this->appCustomerId,
            ],
        ];
    }
}
