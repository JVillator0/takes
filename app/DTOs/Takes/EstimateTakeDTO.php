<?php

declare(strict_types=1);

namespace App\DTOs\Takes;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a take estimate depends on (spec 4 "Estimate"). The prompt text is
 * never part of it: only its Unicode character count (`mb_strlen`, the
 * client's `[...prompt].length`).
 */
final readonly class EstimateTakeDTO
{
    public function __construct(
        public int $characters,
        public TakeSize $size,
        public TakeQuality $quality,
        public int $referenceImages,
    ) {}

    /**
     * @param  array{chars: int|string, size: string, quality: string, references?: int|string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            characters: (int) $data['chars'],
            size: TakeSize::from($data['size']),
            quality: TakeQuality::from($data['quality']),
            referenceImages: (int) ($data['references'] ?? 0),
        );
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            characters: $request->integer('chars'),
            size: $request->enum('size', TakeSize::class) ?? TakeSize::Square,
            quality: $request->enum('quality', TakeQuality::class) ?? TakeQuality::Medium,
            referenceImages: $request->integer('references'),
        );
    }

    /**
     * @param  list<string>  $referenceImages
     */
    public static function forPrompt(string $prompt, TakeSize $size, TakeQuality $quality, array $referenceImages): self
    {
        return new self(mb_strlen($prompt, 'UTF-8'), $size, $quality, count($referenceImages));
    }
}
