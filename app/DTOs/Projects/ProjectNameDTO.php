<?php

declare(strict_types=1);

namespace App\DTOs\Projects;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A project's name (create and rename, spec 4 issue 2): trimmed, at most 80
 * characters (spec 3-02).
 */
final readonly class ProjectNameDTO
{
    public const int MAX_LENGTH = 80;

    public function __construct(public string $name) {}

    /**
     * @param  array{name: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(trim($data['name']));
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self(trim($request->string('name')->toString()));
    }
}
