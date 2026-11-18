<?php

declare(strict_types=1);

use App\Enums\Suggestions\SuggestionFailureReason;
use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Suggestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\Support\Constraint;

/**
 * `suggestions.failure_reason` is a varchar + CHECK built from
 * SuggestionFailureReason (spec 3-02, architecture.md; issue #123). Raw
 * writes, bypassing the enum cast: the database itself refuses a reason the
 * enum does not have.
 */
function failureReasonMigration(): Migration
{
    return require database_path('migrations/2026_11_17_180100_add_failure_reason_check_to_suggestions_table.php');
}

it('rejects a failure reason the enum does not have', function (): void {
    $suggestion = Suggestion::factory()->create(['status' => SuggestionStatus::Failed]);

    Constraint::assertRejects(
        fn () => DB::table('suggestions')->where('id', $suggestion->id)->update(['failure_reason' => 'rate_limited']),
        Constraint::CHECK_VIOLATION,
        'suggestions_failure_reason_check',
    );
});

it('accepts every enum reason and NULL', function (): void {
    foreach (SuggestionFailureReason::cases() as $reason) {
        $suggestion = Suggestion::factory()->create(['status' => SuggestionStatus::Failed]);
        DB::table('suggestions')->where('id', $suggestion->id)->update(['failure_reason' => $reason->value]);
    }

    Suggestion::factory()->create(['status' => SuggestionStatus::Proposed]);

    expect(DB::table('suggestions')->whereNotNull('failure_reason')->pluck('failure_reason')->sort()->values()->all())
        ->toBe(collect(SuggestionFailureReason::cases())->map->value->sort()->values()->all())
        ->and(DB::table('suggestions')->whereNull('failure_reason')->count())->toBe(1);
});

it('validates against existing rows and rolls back cleanly', function (): void {
    $migration = failureReasonMigration();
    $migration->down();

    // Rows as production holds them: every reason the enum cast can write, and NULL.
    foreach (SuggestionFailureReason::cases() as $reason) {
        $suggestion = Suggestion::factory()->create(['status' => SuggestionStatus::Failed]);
        DB::table('suggestions')->where('id', $suggestion->id)->update(['failure_reason' => $reason->value]);
    }
    Suggestion::factory()->create(['status' => SuggestionStatus::Pending]);

    $migration->up();

    expect(DB::scalar("SELECT convalidated FROM pg_constraint WHERE conname = 'suggestions_failure_reason_check'"))->toBeTrue()
        ->and(DB::table('suggestions')->count())->toBe(count(SuggestionFailureReason::cases()) + 1);
});
