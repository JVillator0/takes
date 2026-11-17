<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\ArchiveProjectAction;
use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\EnsureDefaultProjectAction;
use App\Actions\Projects\RenameProjectAction;
use App\DTOs\Billing\BalanceSummaryDTO;
use App\DTOs\Projects\ProjectNameDTO;
use App\DTOs\Takes\EstimateTakeDTO;
use App\Enums\Takes\TakeFilter;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectNameRequest;
use App\Http\Requests\Projects\ShowProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TakeDetailResource;
use App\Http\Resources\TakeResource;
use App\Models\Customer;
use App\Models\Project;
use App\Queries\Projects\GetProjectSpendQuery;
use App\Queries\Projects\GetProjectWorkspaceQuery;
use App\Queries\Projects\ListProjectCoversQuery;
use App\Queries\Projects\ListProjectsQuery;
use App\Queries\Takes\EstimateTakeQuery;
use App\Queries\Takes\GetCustomerTakeQuery;
use App\Queries\Takes\GetTakeDetailQuery;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use App\Support\Pricing\PixelforgeTokenTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects (spec 4 issue 2, P03) and the workspace (P04). A project is
 * never deleted: its takes and their money rows reference it.
 */
final class ProjectController extends Controller
{
    /** P03: the projects, with "Untitled project" made on the first visit. */
    public function index(
        Request $request,
        EnsureDefaultProjectAction $ensureDefaultProject,
        ListProjectsQuery $listProjects,
        ListProjectCoversQuery $covers,
        GetProjectSpendQuery $spend,
    ): Response {
        $customer = $this->customer($request);
        $ensureDefaultProject->execute($customer);

        $search = $request->string('search')->trim()->toString();
        $projects = $listProjects->execute($customer, $search);
        $ids = array_values(array_map(strval(...), $projects->modelKeys()));
        $coverTakes = $covers->execute($ids);
        $spends = $spend->execute($ids);

        return Inertia::render('projects/Index', [
            'projects' => $projects->map(fn (Project $project): array => ProjectResource::summary(
                $project,
                $coverTakes[$project->id] ?? null,
                $spends[$project->id] ?? Nanodollars::zero(),
            ))->values()->all(),
            'search' => $search,
            'balance' => BalanceSummaryDTO::fromCustomer($customer)->toArray(),
        ]);
    }

    /**
     * P04: the composer and the grid; `?take=` opens the drawer (P06), loaded
     * alone with a partial reload `only: ['take']`. Live updates reload
     * `takes`, `counts` and `balance` (spec 5).
     */
    public function show(
        ShowProjectRequest $request,
        GetProjectWorkspaceQuery $workspace,
        GetTakeDetailQuery $takeDetail,
        EstimateTakeQuery $estimateTake,
        GetCustomerTakeQuery $customerTake,
    ): Response {
        $customer = $request->customer();
        $project = $request->project();
        $data = $workspace->execute($project, $request->filter(), $request->integer('pages', 1));
        $takeId = $request->query('take');
        $balance = BalanceSummaryDTO::fromCustomer($customer);

        return Inertia::render('projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'takeCount' => $data->counts[TakeFilter::All->value],
                'spend' => $data->spend->format(4, RoundingMode::HalfUp),
                'spendExact' => $data->spend->formatExact(),
            ],
            'takes' => TakeResource::collection($data->takes)->resolve($request),
            'hasMoreTakes' => $data->hasMore,
            'pages' => $data->pages,
            'filter' => $data->filter->value,
            'counts' => $data->counts,
            'balance' => $balance->toArray(),
            'smallestTake' => fn (): array => $this->smallestTake($estimateTake, $balance),
            'take' => is_string($takeId) && $takeId !== ''
                ? fn (): array => (new TakeDetailResource($takeDetail->execute(
                    $customerTake->execute($customer, $takeId, $project) ?? abort(404),
                )))->resolve($request)
                : null,
            'composer' => [
                'maxPromptChars' => PixelforgeTokenTable::MAX_PROMPT_CHARACTERS,
                'maxReferences' => PixelforgeTokenTable::MAX_REFERENCE_IMAGES,
                'samplePrompt' => $data->counts[TakeFilter::All->value] === 0 ? __('takes.projects.sample_prompt') : null,
            ],
        ]);
    }

    public function store(ProjectNameRequest $request, CreateProjectAction $createProject): RedirectResponse
    {
        $project = $createProject->execute($request->customer(), ProjectNameDTO::fromRequest($request));

        return to_route('projects.show', $project);
    }

    public function update(ProjectNameRequest $request, Project $project, RenameProjectAction $renameProject): RedirectResponse
    {
        $renameProject->execute($project, ProjectNameDTO::fromRequest($request));

        return back();
    }

    public function destroy(Request $request, Project $project, ArchiveProjectAction $archiveProject): RedirectResponse
    {
        $customer = $request->user();
        assert($customer instanceof Customer);

        Gate::forUser($customer)->authorize('update', $project);

        $archiveProject->execute($project);

        return to_route('projects.index');
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user();
        assert($customer instanceof Customer);

        return $customer;
    }

    /**
     * The low-balance banner (2-01): shown when the available credits are
     * below the smallest possible take's maximum price.
     *
     * @return array{max: string, below: bool}
     */
    private function smallestTake(EstimateTakeQuery $estimateTake, BalanceSummaryDTO $balance): array
    {
        $max = $estimateTake->execute(new EstimateTakeDTO(1, TakeSize::Square, TakeQuality::Low, 0))->estimate->maxCharge;

        return [
            'max' => $max->format(4, RoundingMode::Ceil),
            'below' => $balance->available->lessThan($max),
        ];
    }
}
