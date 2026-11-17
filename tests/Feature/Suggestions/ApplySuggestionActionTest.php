<?php

declare(strict_types=1);

use App\Actions\Suggestions\ApplySuggestionAction;
use App\Actions\Suggestions\DiscardSuggestionAction;
use App\Contracts\Takes\CreatesSuggestedTake;
use App\DTOs\Suggestions\SuggestedTakeDTO;
use App\DTOs\Suggestions\TakeChangeProposalDTO;
use App\Enums\Suggestions\SuggestionStatus;
use App\Enums\Takes\TakeSource;
use App\Enums\Takes\TakeStatus;
use App\Exceptions\Suggestions\BaseImageUnavailableException;
use App\Exceptions\Suggestions\SuggestionNotProposedException;
use App\Exceptions\Takes\TakeCreationUnavailableException;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Suggestion;
use App\Models\Take;
use App\Support\Suggestions\ProposalReferences;
use App\Support\Takes\UnavailableSuggestedTakeCreator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Tests\Support\Suggestions;

/*
 * ApplySuggestionAction and DiscardSuggestionAction (spec 8 step 4). Take
 * creation is spec 4's CreateTakeAction behind the CreatesSuggestedTake
 * seam; here a recording stand-in honours the seam's contract (idempotent
 * on the key, called under the suggestion lock).
 */

/** A CreatesSuggestedTake that records its calls and replays on the idempotency key, like CreateTakeAction. */
final class RecordingTakeCreator implements CreatesSuggestedTake
{
    /** @var list<array{level: int, dto: SuggestedTakeDTO}> */
    public array $calls = [];

    public function create(Customer $customer, Project $project, SuggestedTakeDTO $take): Take
    {
        $this->calls[] = ['level' => DB::transactionLevel(), 'dto' => $take];

        $existing = Take::query()->where('customer_id', $customer->id)->where('idempotency_key', $take->idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $created = new Take;
        $created->forceFill([
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'parent_take_id' => $take->parentTakeId,
            'suggestion_id' => $take->suggestionId,
            'source' => $take->source,
            'idempotency_key' => $take->idempotencyKey,
            'status' => TakeStatus::Pending,
            'prompt' => $take->prompt,
            'size' => $take->size,
            'quality' => $take->quality,
            'reference_images' => $take->referenceImages,
        ])->save();

        return $created;
    }
}

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->base = Suggestions::baseTake($this->customer);
    $this->suggestion = Suggestion::factory()->proposed()->create([
        'customer_id' => $this->customer->id,
        'project_id' => $this->base->project_id,
        'base_take_id' => $this->base->id,
        'proposal' => Suggestions::proposal(),
    ]);
    $this->creator = new RecordingTakeCreator;
    app()->instance(CreatesSuggestedTake::class, $this->creator);
});

function applySuggestion(?Suggestion $suggestion = null): Take
{
    return app(ApplySuggestionAction::class)->execute(test()->customer, $suggestion ?? test()->suggestion);
}

it('creates the ai_change take with lineage and the proposal, and marks the suggestion applied', function (): void {
    $baseline = DB::transactionLevel();

    $take = applySuggestion();
    $suggestion = $this->suggestion->refresh();

    expect($take->source)->toBe(TakeSource::AiChange)
        ->and($take->parent_take_id)->toBe($this->base->id)
        ->and($take->suggestion_id)->toBe($suggestion->id)
        ->and($take->prompt)->toBe(Suggestions::proposal()['prompt'])
        ->and($take->size->value)->toBe('1024x1536')
        ->and($take->quality->value)->toBe('high')
        ->and($suggestion->status)->toBe(SuggestionStatus::Applied)
        ->and($suggestion->resulting_take_id)->toBe($take->id)
        // Created inside the apply transaction, which holds the suggestion lock.
        ->and($this->creator->calls[0]['level'])->toBe($baseline + 1);
});

it('re-attaches the real reference URLs: kept references plus the base image', function (array $flags, Closure $expected): void {
    $this->suggestion->forceFill(['proposal' => Suggestions::proposal($flags)])->save();

    $take = applySuggestion();

    expect($take->reference_images)->toBe($expected($this->base));
})->with([
    'keep and add' => [['keep_references' => true, 'add_base_image_as_reference' => true], fn (Take $base): array => [...$base->reference_images, $base->image_url]],
    'keep only' => [['keep_references' => true, 'add_base_image_as_reference' => false], fn (Take $base): array => $base->reference_images],
    'drop all' => [['keep_references' => false, 'add_base_image_as_reference' => false], fn (): array => []],
    'base image only' => [['keep_references' => false, 'add_base_image_as_reference' => true], fn (Take $base): array => [$base->image_url]],
]);

it('derives the take idempotency key as uuid v5 of the suggestion id', function (): void {
    applySuggestion();

    expect($this->creator->calls[0]['dto']->idempotencyKey)
        ->toBe(Uuid::uuid5(ApplySuggestionAction::IDEMPOTENCY_NAMESPACE, 'suggestion:'.$this->suggestion->id)->toString());
});

it('replays a second apply: same take, no second take', function (): void {
    $first = applySuggestion();
    $second = applySuggestion();

    expect($second->id)->toBe($first->id)
        ->and(Take::query()->where('suggestion_id', $this->suggestion->id)->count())->toBe(1)
        // The applied suggestion short-circuits: the take creator is called once.
        ->and($this->creator->calls)->toHaveCount(1);
});

it('cannot create a second take for one suggestion (partial unique index)', function (): void {
    applySuggestion();

    expect(fn () => Take::factory()->create([
        'customer_id' => $this->customer->id,
        'project_id' => $this->base->project_id,
        'suggestion_id' => $this->suggestion->id,
        'source' => TakeSource::AiChange,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a suggestion that is not proposed', function (SuggestionStatus $status): void {
    $this->suggestion->forceFill(['status' => $status])->save();

    expect(fn () => applySuggestion())->toThrow(SuggestionNotProposedException::class)
        ->and($this->creator->calls)->toBe([]);
})->with([SuggestionStatus::Pending, SuggestionStatus::Failed, SuggestionStatus::Discarded]);

it('refuses another customer\'s suggestion', function (): void {
    $this->customer = Customer::factory()->create();

    expect(fn () => applySuggestion())->toThrow(AuthorizationException::class);
});

it('refuses and keeps the suggestion proposed until spec 4 binds take creation', function (): void {
    app()->forgetInstance(CreatesSuggestedTake::class);
    app()->bind(CreatesSuggestedTake::class, UnavailableSuggestedTakeCreator::class);

    expect(fn () => applySuggestion())->toThrow(TakeCreationUnavailableException::class)
        ->and($this->suggestion->refresh()->status)->toBe(SuggestionStatus::Proposed)
        ->and(Take::query()->count())->toBe(1);
});

describe('discard', function (): void {
    it('discards a proposal, and a second discard is a no-op', function (): void {
        $action = app(DiscardSuggestionAction::class);

        expect($action->execute($this->customer, $this->suggestion)->status)->toBe(SuggestionStatus::Discarded)
            ->and($action->execute($this->customer, $this->suggestion)->status)->toBe(SuggestionStatus::Discarded);
    });

    it('refuses to discard an applied suggestion', function (): void {
        applySuggestion();

        expect(fn () => app(DiscardSuggestionAction::class)->execute($this->customer, $this->suggestion))
            ->toThrow(SuggestionNotProposedException::class);
    });
});

describe('base image reference (spec 4 remix source order)', function (): void {
    it('prefers the persisted copy as a temporary URL over the provider URL', function (): void {
        Storage::fake('local');
        Storage::disk('local')->buildTemporaryUrlsUsing(fn (string $path): string => "https://files.test/{$path}?signature=1");
        $this->base->forceFill(['image_path' => 'takes/base.png'])->save();

        $take = applySuggestion();

        expect($take->reference_images)->toBe([...$this->base->reference_images, 'https://files.test/takes/base.png?signature=1']);
    });

    it('refuses apply when the base image has no usable URL, and keeps the proposal', function (array $image): void {
        $this->base->forceFill($image)->save();

        expect(fn () => applySuggestion())->toThrow(BaseImageUnavailableException::class)
            ->and($this->suggestion->refresh()->status)->toBe(SuggestionStatus::Proposed)
            ->and($this->creator->calls)->toBe([]);
    })->with([
        'provider URL expired' => [['image_path' => null, 'image_url_expires_at' => now()->subMinute()]],
        // A succeeded take always has an image_url (takes_succeeded_has_result_check); with no
        // recorded expiry it is valid for 24 h after completion.
        'no expiry recorded, completed two days ago' => [['image_path' => null, 'image_url_expires_at' => null, 'completed_at' => now()->subDays(2)]],
    ]);

    it('applies without the base image when the proposal does not ask for it, even if it expired', function (): void {
        $this->base->forceFill(['image_url_expires_at' => now()->subMinute()])->save();
        $this->suggestion->forceFill(['proposal' => Suggestions::proposal(['add_base_image_as_reference' => false])])->save();

        expect(applySuggestion()->reference_images)->toBe($this->base->reference_images);
    });

    it('prices exactly the references apply attaches', function (): void {
        $proposal = TakeChangeProposalDTO::fromArray(Suggestions::proposal());

        expect(ProposalReferences::count($proposal, $this->base))->toBe(2);

        $this->base->forceFill(['image_url_expires_at' => now()->subMinute()])->save();

        expect(ProposalReferences::count($proposal, $this->base))->toBe(1)
            ->and(ProposalReferences::baseImageUnavailable($proposal, $this->base))->toBeTrue();
    });
});
