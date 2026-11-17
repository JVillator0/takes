<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Project;
use App\Models\Suggestion;
use App\Models\Take;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Constraint;

/**
 * Raw inserts, bypassing the models: the database itself must refuse an
 * invalid take (spec 3-02 Tests).
 */
beforeEach(function (): void {
    $this->project = Project::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function takeRow(Project $project, array $overrides = []): array
{
    return [
        'id' => (string) Str::ulid(),
        'customer_id' => $project->customer_id,
        'project_id' => $project->id,
        'source' => 'composer',
        'idempotency_key' => (string) Str::uuid(),
        'status' => 'pending',
        'prompt' => 'A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

it('accepts valid takes in every state', function (): void {
    $rows = [
        takeRow($this->project),
        takeRow($this->project, ['status' => 'queued', 'pixelforge_id' => 'gen_queued']),
        takeRow($this->project, ['status' => 'succeeded', 'completed_at' => now(), 'image_url' => 'https://example.test/a.png']),
        takeRow($this->project, ['status' => 'failed', 'completed_at' => now(), 'failure_code' => 'submit_timeout']),
        takeRow($this->project, ['reference_images' => json_encode(['a', 'b', 'c', 'd'])]),
        // 4000 Unicode characters is the limit, however many bytes they take.
        takeRow($this->project, ['prompt' => str_repeat('é', 3999).'🎬']),
    ];

    foreach ($rows as $row) {
        DB::table('takes')->insert($row);
    }

    expect(DB::table('takes')->count())->toBe(count($rows));
});

it('rejects a take that breaks a CHECK constraint', function (array $overrides, string $constraint): void {
    Constraint::assertRejects(
        fn () => DB::table('takes')->insert(takeRow($this->project, $overrides)),
        Constraint::CHECK_VIOLATION,
        $constraint,
    );
})->with([
    'unknown status' => [['status' => 'done'], 'takes_status_check'],
    'unknown source' => [['source' => 'upload'], 'takes_source_check'],
    'unknown size' => [['size' => '512x512'], 'takes_size_check'],
    'unknown quality' => [['quality' => 'ultra'], 'takes_quality_check'],
    'unknown failure code' => [['failure_code' => 'oops'], 'takes_failure_code_check'],
    'unknown submit error kind' => [['last_submit_error_kind' => 'busy'], 'takes_last_submit_error_kind_check'],
    'unknown attention reason' => [['attention_reason' => 'stuck'], 'takes_attention_reason_check'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('é', 4001)], 'takes_prompt_length_check'],
    'more than 4 reference images' => [['reference_images' => json_encode(['a', 'b', 'c', 'd', 'e'])], 'takes_reference_images_check'],
    'reference images not an array' => [['reference_images' => json_encode(['url' => 'a'])], 'takes_reference_images_check'],
    'negative submit attempts' => [['submit_attempts' => -1], 'takes_submit_attempts_check'],
    'failed without failure code' => [['status' => 'failed', 'completed_at' => now()], 'takes_failed_has_code_check'],
    'succeeded without image url' => [['status' => 'succeeded', 'completed_at' => now()], 'takes_succeeded_has_result_check'],
    'succeeded without completed_at' => [['status' => 'succeeded', 'image_url' => 'https://example.test/a.png'], 'takes_succeeded_has_result_check'],
    'failed without completed_at' => [['status' => 'failed', 'failure_code' => 'cancelled'], 'takes_terminal_has_completed_at_check'],
]);

it('rejects a replayed idempotency key for the same customer only', function (): void {
    $key = (string) Str::uuid();
    DB::table('takes')->insert(takeRow($this->project, ['idempotency_key' => $key]));

    Constraint::assertRejects(
        fn () => DB::table('takes')->insert(takeRow($this->project, ['idempotency_key' => $key])),
        Constraint::UNIQUE_VIOLATION,
        'takes_customer_id_idempotency_key_unique',
    );

    // Another customer may (by chance) use the same uuid.
    DB::table('takes')->insert(takeRow(Project::factory()->create(), ['idempotency_key' => $key]));

    expect(DB::table('takes')->where('idempotency_key', $key)->count())->toBe(2);
});

it('rejects a second take for the same AI suggestion', function (): void {
    $suggestion = Suggestion::factory()->create([
        'customer_id' => $this->project->customer_id,
        'project_id' => $this->project->id,
    ]);
    DB::table('takes')->insert(takeRow($this->project, ['suggestion_id' => $suggestion->id, 'source' => 'ai_change']));

    Constraint::assertRejects(
        fn () => DB::table('takes')->insert(takeRow($this->project, ['suggestion_id' => $suggestion->id, 'source' => 'ai_change'])),
        Constraint::UNIQUE_VIOLATION,
        'takes_suggestion_id_unique',
    );

    // Many takes without a suggestion are fine (the index is partial).
    DB::table('takes')->insert(takeRow($this->project));
    DB::table('takes')->insert(takeRow($this->project));
});

it('rejects a take or suggestion in another customer\'s project', function (): void {
    $otherProject = Project::factory()->create();

    Constraint::assertRejects(
        fn () => DB::table('takes')->insert(takeRow($this->project, ['project_id' => $otherProject->id])),
        Constraint::FOREIGN_KEY_VIOLATION,
        'takes_project_id_customer_id_foreign',
    );

    $suggestion = Suggestion::factory()->create();

    Constraint::assertRejects(
        fn () => DB::table('suggestions')->where('id', $suggestion->id)->update(['project_id' => $otherProject->id]),
        Constraint::FOREIGN_KEY_VIOLATION,
        'suggestions_project_id_customer_id_foreign',
    );
});

it('rejects lineage and suggestion links to another customer\'s rows', function (): void {
    $otherTake = Take::factory()->create();
    $otherSuggestion = Suggestion::factory()->create();
    $suggestion = Suggestion::factory()->create([
        'customer_id' => $this->project->customer_id,
        'project_id' => $this->project->id,
    ]);

    Constraint::assertRejects(
        fn () => DB::table('takes')->insert(takeRow($this->project, ['parent_take_id' => $otherTake->id, 'source' => 'remix'])),
        Constraint::FOREIGN_KEY_VIOLATION,
        'takes_parent_take_id_customer_id_foreign',
    );

    Constraint::assertRejects(
        fn () => DB::table('takes')->insert(takeRow($this->project, ['suggestion_id' => $otherSuggestion->id, 'source' => 'ai_change'])),
        Constraint::FOREIGN_KEY_VIOLATION,
        'takes_suggestion_id_customer_id_foreign',
    );

    Constraint::assertRejects(
        fn () => DB::table('suggestions')->where('id', $suggestion->id)->update(['base_take_id' => $otherTake->id]),
        Constraint::FOREIGN_KEY_VIOLATION,
        'suggestions_base_take_id_customer_id_foreign',
    );

    Constraint::assertRejects(
        fn () => DB::table('suggestions')->where('id', $suggestion->id)->update(['resulting_take_id' => $otherTake->id]),
        Constraint::FOREIGN_KEY_VIOLATION,
        'suggestions_resulting_take_id_customer_id_foreign',
    );
});

it('rejects a duplicate Pixelforge generation id', function (): void {
    DB::table('takes')->insert(takeRow($this->project, ['pixelforge_id' => 'gen_abc']));

    Constraint::assertRejects(
        fn () => DB::table('takes')->insert(takeRow($this->project, ['pixelforge_id' => 'gen_abc'])),
        Constraint::UNIQUE_VIOLATION,
        'takes_pixelforge_id_unique',
    );
});

it('rejects a negative hold on the customer', function (): void {
    $customer = Customer::factory()->create();

    Constraint::assertRejects(
        fn () => DB::table('customers')->where('id', $customer->id)->update(['held_nanos' => -1]),
        Constraint::CHECK_VIOLATION,
        'customers_held_nanos_check',
    );

    // A negative balance is allowed (actual > hold, spec 3-03 §6).
    DB::table('customers')->where('id', $customer->id)->update(['balance_nanos' => -1]);
});

it('rejects invalid suggestion and webhook rows', function (): void {
    $suggestion = Suggestion::factory()->create();

    Constraint::assertRejects(
        fn () => DB::table('suggestions')->where('id', $suggestion->id)->update(['status' => 'timeout']),
        Constraint::CHECK_VIOLATION,
        'suggestions_status_check',
    );

    Constraint::assertRejects(
        fn () => DB::table('suggestions')->insert([
            'id' => (string) Str::ulid(),
            'customer_id' => $suggestion->customer_id,
            'project_id' => $suggestion->project_id,
            'base_take_id' => $suggestion->base_take_id,
            'instruction' => 'Again',
            'request_key' => $suggestion->request_key,
            'expires_at' => now(),
        ]),
        Constraint::UNIQUE_VIOLATION,
        'suggestions_customer_id_request_key_unique',
    );

    DB::table('webhook_events')->insert(['event_id' => 'evt_1', 'type' => 'generation.succeeded', 'payload' => '{}', 'received_at' => now()]);

    Constraint::assertRejects(
        fn () => DB::table('webhook_events')->insert(['event_id' => 'evt_1', 'type' => 'generation.succeeded', 'payload' => '{}', 'received_at' => now()]),
        Constraint::UNIQUE_VIOLATION,
        'webhook_events_event_id_unique',
    );

    Constraint::assertRejects(
        fn () => DB::table('webhook_deliveries')->insert(['event_id' => 'evt_1', 'type' => 'generation.succeeded', 'attempt' => 1, 'outcome' => 'invalid_signature', 'received_at' => now()]),
        Constraint::CHECK_VIOLATION,
        'webhook_deliveries_outcome_check',
    );
});
