<?php

declare(strict_types=1);

namespace App\Http\Requests\Takes;

use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Http\Requests\CustomerFormRequest;
use App\Models\Project;
use App\Models\Take;
use App\Support\Pricing\PixelforgeTokenTable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * `POST /projects/{project}/takes` (spec 4 "Create take"): the same limits
 * as Pixelforge (prompt 1–4000 Unicode characters, at most 4 http(s)
 * reference URLs of ≤ 2048 characters), so we never send a request we know
 * it will reject with 422 (P05-B), plus the composer's idempotency key
 * (D-033) and an optional parent take.
 *
 * Ownership failures are 403 here (the project, the parent take). The
 * credit, cap and restriction checks are CreateTakeAction's, under the
 * customer lock, with their DenialReason codes.
 */
final class StoreTakeRequest extends CustomerFormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project || $project->customer_id !== $this->customer()->id || $project->archived_at !== null) {
            return false;
        }

        $parent = $this->input('parent_take_id');

        return $parent === null || $parent === '' || (is_string($parent) && Take::query()
            ->whereKey($parent)
            ->where('customer_id', $this->customer()->id)
            ->exists());
    }

    /**
     * Blank reference rows from the composer are dropped before validation.
     */
    protected function prepareForValidation(): void
    {
        $references = $this->input('reference_images');

        if (is_array($references)) {
            $this->merge(['reference_images' => array_values(array_filter(
                array_map(fn (mixed $url): mixed => is_string($url) ? trim($url) : $url, $references),
                fn (mixed $url): bool => $url !== '' && $url !== null,
            ))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Laravel measures strings with mb_strlen: Unicode characters, as Pixelforge does.
            'prompt' => ['required', 'string', 'max:'.PixelforgeTokenTable::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(TakeSize::class)],
            'quality' => ['required', Rule::enum(TakeQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.PixelforgeTokenTable::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https'],
            'idempotency_key' => ['required', 'uuid'],
            'parent_take_id' => ['nullable', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.*.url' => __('takes.composer.reference_invalid'),
        ];
    }
}
