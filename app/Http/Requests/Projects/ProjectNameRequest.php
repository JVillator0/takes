<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\DTOs\Projects\ProjectNameDTO;
use App\Http\Requests\CustomerFormRequest;
use App\Models\Project;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /projects` and `PATCH /projects/{project}` (spec 4 issue 2): one
 * field, the name (P03). Creating and renaming need `projects.manage`;
 * renaming someone else's project is a 404 (ProjectPolicy).
 */
final class ProjectNameRequest extends CustomerFormRequest
{
    public function authorize(): Response
    {
        $project = $this->route('project');

        return $project instanceof Project
            ? Gate::inspect('update', $project)
            : Gate::inspect('create', Project::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ProjectNameDTO::MAX_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
