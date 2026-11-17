<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Enums\Takes\TakeFilter;
use App\Http\Requests\CustomerFormRequest;
use App\Models\Project;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * `GET /projects/{project}` (P04): `?status=` filter, `?pages=` for "load
 * more", `?take=` for the drawer (P06). Another customer's project is a 404
 * (ProjectPolicy), and so is an archived one.
 */
final class ShowProjectRequest extends CustomerFormRequest
{
    public function authorize(): Response
    {
        $project = $this->route('project');

        if (! $project instanceof Project || $project->archived_at !== null) {
            return Response::denyAsNotFound();
        }

        return Gate::inspect('view', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(TakeFilter::class)],
            'pages' => ['nullable', 'integer', 'min:1'],
            'take' => ['nullable', 'string'],
        ];
    }

    public function filter(): TakeFilter
    {
        return $this->enum('status', TakeFilter::class) ?? TakeFilter::All;
    }

    public function project(): Project
    {
        $project = $this->route('project');
        assert($project instanceof Project);

        return $project;
    }
}
