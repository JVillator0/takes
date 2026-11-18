<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Enums\Billing\ReconciliationStatus;
use App\Enums\Takes\TakeStatus;
use App\Events\TakeUpdated;
use App\Exceptions\Pixelforge\AdminRequestBudgetExhaustedException;
use App\Filament\Admin\Clusters\Providers\Pages\PixelforgeUsage;
use App\Filament\Admin\Clusters\Providers\Widgets\PixelforgeDiscrepancies;
use App\Filament\Admin\Clusters\Studio\Resources\Takes\TakeResource;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Queries\Billing\GetPixelforgeReconciliationQuery;
use App\Queries\Billing\ListPixelforgeDayDiscrepanciesQuery;
use App\Support\Pixelforge\AdminRequestBudget;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PixelforgeFixture;
use Tests\Support\Takes;

// Spec 7 issue 4 (#64), A04 (D-034, D-058): settled usage vs GET /v1/usage per UTC day, "in flight" today, and the
// Find takes drill-down with its four groups. Pixelforge is faked; the fixture generation costs 305,510,000 nanos.

const FIXTURE_COST_NANOS = 305_510_000;

beforeEach(function (): void {
    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pf_test_0123456789abcdef']);
    Cache::flush();
    Http::preventStrayRequests();
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
});

/**
 * @param  array<string, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string}>  $days  date => [gens, text, image, output, reasoning, cost_usd]
 * @return array<string, mixed>
 */
function usagePayload(string $from, string $to, array $days): array
{
    $data = [];

    for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
        [$generations, $text, $image, $output, $reasoning, $cost] = $days[$day->toDateString()] ?? [0, 0, 0, 0, 0, '0.000000000'];
        $data[] = ['date' => $day->toDateString(), 'generations' => $generations, 'input_text_tokens' => $text, 'input_image_tokens' => $image,
            'output_tokens' => $output, 'reasoning_tokens' => $reasoning, 'cost_usd' => $cost];
    }

    return ['object' => 'usage', 'start_date' => $from, 'end_date' => $to, 'data' => $data];
}

/** One settled Pixelforge usage for a succeeded take, the fixture's tokens and cost. */
function settledFixtureTake(string $billingDate, string $generationId, int $costNanos = FIXTURE_COST_NANOS): Take
{
    $take = Take::factory()->succeeded()->create(['pixelforge_id' => $generationId]);
    UsageRecord::factory()->settled($costNanos)->create([
        'customer_id' => $take->customer_id,
        'billable_id' => $take->id,
        'billing_date' => $billingDate,
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
    ]);

    return $take;
}

it('matches a day on every field, shows today as in flight and counts the days that match', function (): void {
    settledFixtureTake('2026-11-15', 'gen_a');
    Take::factory()->processing()->create(['created_at' => now()]);
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-15', '2026-11-17', [
        '2026-11-15' => [1, 12, 765, 6450, 3184, '0.305510000'],
    ]))]);

    $report = app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-17'));

    expect(array_map(fn ($day): string => $day->date.':'.$day->status->value, $report->days))
        ->toBe(['2026-11-17:pending', '2026-11-16:match', '2026-11-15:match'])
        ->and($report->days[0]->inFlight)->toBe(1)
        ->and([$report->matchedDays, $report->judgedDays])->toBe([2, 2])
        ->and($report->oursTotal->cost->toInt())->toBe(FIXTURE_COST_NANOS)
        ->and($report->unavailable)->toBeFalse();
});

it('flags a mismatch on tokens even when the costs offset', function (): void {
    settledFixtureTake('2026-11-15', 'gen_a');
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-15', '2026-11-16', [
        '2026-11-15' => [1, 12, 765, 6451, 3184, '0.305510000'],
    ]))]);

    $day = app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-16'))->days[1];

    expect($day->status)->toBe(ReconciliationStatus::Mismatch)
        ->and($day->ours->differences($day->theirs ?? $day->ours))->toBe(['output_tokens' => -1]);
});

it('caches the usage call for five minutes, refetches on refresh and falls back when Pixelforge is down', function (): void {
    $query = app(GetPixelforgeReconciliationQuery::class);
    $from = CarbonImmutable::parse('2026-11-15');
    $to = CarbonImmutable::parse('2026-11-16');
    Http::fakeSequence('pixelforge.test/v1/usage*')
        ->push(usagePayload('2026-11-15', '2026-11-16', []))
        ->push(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503)
        ->push(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503)
        ->push(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503);

    $query->execute($from, $to);
    $query->execute($from, $to);
    Http::assertSentCount(1);

    $fallback = $query->execute($from, $to, refresh: true);

    expect($fallback->unavailable)->toBeTrue()
        ->and($fallback->days[0]->status)->toBe(ReconciliationStatus::Match)
        ->and($fallback->fetchedAt?->toDateTimeString())->toBe('2026-11-17 12:00:00');
});

it('marks days Unavailable when Pixelforge is down and nothing was fetched before', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503)]);

    $report = app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-16'));

    expect($report->unavailable)->toBeTrue()
        ->and(array_map(fn ($day): ReconciliationStatus => $day->status, $report->days))->toBe([ReconciliationStatus::Unavailable, ReconciliationStatus::Unavailable])
        ->and($report->theirsTotal)->toBeNull()
        ->and($report->fetchedAt)->toBeNull();
});

it('keeps a past day with a take still in flight Pending (2-03), and refuses a range over 92 days', function (): void {
    Take::factory()->processing()->create(['created_at' => '2026-11-15 09:00:00']);
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-15', '2026-11-16', [
        '2026-11-15' => [1, 12, 765, 6450, 3184, '0.305510000'],
    ]))]);
    $query = app(GetPixelforgeReconciliationQuery::class);

    expect($query->execute(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-16'))->days[1]->status)->toBe(ReconciliationStatus::Pending)
        ->and(fn () => $query->execute(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-11-16')))->toThrow(InvalidArgumentException::class);
});

it('caches each day under its own key for the dashboard and Reports', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-15', '2026-11-16', [
        '2026-11-15' => [1, 12, 765, 6450, 3184, '0.305510000'],
    ]))]);

    app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-16'));
    app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-16'));

    expect(Cache::get(GetPixelforgeReconciliationQuery::dayKey('2026-11-15'))['totals']['cost_nanos'] ?? null)->toBe(FIXTURE_COST_NANOS);
    Http::assertSentCount(1);
});

it('finds the four groups of a mismatching day across provider pages', function (): void {
    settledFixtureTake('2026-11-15', 'gen_ok');
    settledFixtureTake('2026-11-15', 'gen_differs', costNanos: 300_000_000);
    $unsettled = Take::factory()->processing()->create(['pixelforge_id' => 'gen_unsettled']);
    UsageRecord::factory()->create(['customer_id' => $unsettled->customer_id, 'billable_id' => $unsettled->id]);
    $orphan = Take::factory()->failed()->create(['pixelforge_id' => 'gen_orphan']);
    $done = fn (string $id, string $completedAt = '2026-11-15T10:00:00Z'): array => PixelforgeFixture::generation('succeeded', ['id' => $id, 'created_at' => $completedAt, 'completed_at' => $completedAt]);

    Http::fakeSequence('pixelforge.test/v1/generations*')
        ->push(['object' => 'list', 'data' => [$done('gen_ok'), $done('gen_unknown'), $done('gen_other_day', '2026-11-14T23:59:59Z')], 'has_more' => true, 'next_cursor' => 'cur_2'])
        ->push(['object' => 'list', 'data' => [$done('gen_differs'), $done('gen_unsettled'), $done('gen_orphan')], 'has_more' => false, 'next_cursor' => null]);

    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));
    $ids = fn (array $rows): array => array_map(fn ($row): string => $row->generationId, $rows);

    expect($ids($result->theirsNotOurs))->toBe(['gen_unknown'])
        ->and($ids($result->oursNotSettled))->toBe(['gen_unsettled'])
        ->and($ids($result->amountDiffers))->toBe(['gen_differs'])
        ->and($ids($result->orphanSuccess))->toBe(['gen_orphan'])
        ->and($result->orphanSuccess[0]->takeId)->toBe($orphan->id)
        ->and($result->amountDiffers[0]->theirCost?->toInt())->toBe(FIXTURE_COST_NANOS)
        ->and($result->truncated)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cursor=cur_2') && str_contains($request->url(), 'status=succeeded'));
});

it('puts a settled take with equal cost but different tokens, or another billing day, in Amount differs', function (): void {
    $tokens = settledFixtureTake('2026-11-15', 'gen_tokens');
    $tokens->usageRecord()->update(['output_tokens' => 6449, 'reasoning_tokens' => 3185]);
    settledFixtureTake('2026-11-14', 'gen_day');
    Http::fake(['pixelforge.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [
        PixelforgeFixture::generation('succeeded', ['id' => 'gen_tokens', 'completed_at' => '2026-11-15T10:00:00Z']),
        PixelforgeFixture::generation('succeeded', ['id' => 'gen_day', 'completed_at' => '2026-11-15T00:00:01Z']),
    ], 'has_more' => false, 'next_cursor' => null])]);

    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));

    expect(array_map(fn ($row): string => $row->generationId, $result->amountDiffers))->toBe(['gen_tokens', 'gen_day']);
});

it('matches by app_take_id only for a take without another generation', function (): void {
    $linked = settledFixtureTake('2026-11-15', 'gen_first');
    $orphan = Take::factory()->failed()->create(['pixelforge_id' => null]);
    $generation = fn (string $id, string $takeId): array => PixelforgeFixture::generation('succeeded', [
        'id' => $id, 'completed_at' => '2026-11-15T10:00:00Z', 'metadata' => ['app_take_id' => $takeId],
    ]);
    Http::fake(['pixelforge.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [
        $generation('gen_first', $linked->id),
        $generation('gen_second', $linked->id),
        $generation('gen_orphan_meta', $orphan->id),
    ], 'has_more' => false, 'next_cursor' => null])]);

    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));

    expect(array_map(fn ($row): string => $row->generationId, $result->theirsNotOurs))->toBe(['gen_second'])
        ->and(array_map(fn ($row): ?string => $row->takeId, $result->orphanSuccess))->toBe([$orphan->id])
        ->and($result->amountDiffers)->toBe([]);
});

it('stops paging once a whole page is older than the day, and flags truncation at the page cap', function (): void {
    $page = fn (string $createdAt, string $cursor): array => ['object' => 'list', 'data' => [
        PixelforgeFixture::generation('succeeded', ['id' => 'gen_'.$cursor, 'created_at' => $createdAt, 'completed_at' => $createdAt]),
    ], 'has_more' => true, 'next_cursor' => $cursor];
    Http::fakeSequence('pixelforge.test/v1/generations*')
        ->push($page('2026-11-15T10:00:00Z', 'c2'))
        ->push($page('2026-11-12T10:00:00Z', 'c3'))
        ->push($page('2026-11-11T10:00:00Z', 'c4'));

    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));

    expect($result->truncated)->toBeFalse()->and($result->theirsNotOurs)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('flags truncation after the page cap', function (): void {
    Http::fake(['pixelforge.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [
        PixelforgeFixture::generation('succeeded', ['id' => 'gen_x', 'created_at' => '2026-11-15T10:00:00Z', 'completed_at' => '2026-11-15T10:00:00Z']),
    ], 'has_more' => true, 'next_cursor' => 'again'])]);

    expect(app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'))->truncated)->toBeTrue();
    Http::assertSentCount(ListPixelforgeDayDiscrepanciesQuery::MAX_PAGES);
});

it('renders for the auditor with 9-decimal costs, the summary and Find takes on a mismatch', function (): void {
    settledFixtureTake('2026-11-15', 'gen_a');
    Http::fake([
        'pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-04', '2026-11-17', [
            '2026-11-15' => [2, 24, 1530, 12900, 6368, '0.611020000'],
        ])),
        'pixelforge.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [
            PixelforgeFixture::generation('succeeded', ['id' => 'gen_a', 'completed_at' => '2026-11-15T10:00:00Z']),
            PixelforgeFixture::generation('succeeded', ['id' => 'gen_b', 'completed_at' => '2026-11-15T11:00:00Z']),
        ], 'has_more' => false, 'next_cursor' => null]),
    ]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    $page = Livewire::test(PixelforgeUsage::class)
        ->assertOk()
        ->assertCanSeeTableRecords(['2026-11-17', '2026-11-15', '2026-11-04'])
        ->assertTableColumnStateSet('theirs', '0.611020000', '2026-11-15')
        ->assertTableColumnStateSet('difference', '-0.305510000', '2026-11-15')
        ->assertTableColumnStateSet('status', 'Mismatch', '2026-11-15')
        ->assertTableColumnStateSet('status', 'Match', '2026-11-16')
        ->assertSee('12 of 13 closed days match')
        ->assertSee('Billing day = the UTC day the generation completed')
        ->assertActionVisible(TestAction::make('findTakes')->table('2026-11-15'))
        ->assertActionHidden(TestAction::make('findTakes')->table('2026-11-16'))
        ->callAction(TestAction::make('findTakes')->table('2026-11-15'));

    expect($page->get('findResult')['groups']['theirs_not_ours'] ?? [])->toHaveCount(1);

    Livewire::test(PixelforgeDiscrepancies::class, ['findResult' => $page->get('findResult')])
        ->assertSee('Find takes · 2026-11-15')
        ->assertTableColumnStateSet('group_label', 'Theirs, not ours', 'theirs_not_ours-0')
        ->assertTableColumnStateSet('generation', 'gen_b', 'theirs_not_ours-0')
        ->assertActionHidden(TestAction::make('reconcile')->table('theirs_not_ours-0'));

    $page->callAction('refresh')->assertSet('findResult', null);
});

it('runs Find takes only for a Mismatch day of the current range, and reports a provider error', function (): void {
    settledFixtureTake('2026-11-15', 'gen_a');
    Http::fake([
        'pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-04', '2026-11-17', [
            '2026-11-15' => [2, 24, 1530, 12900, 6368, '0.611020000'],
        ])),
        'pixelforge.test/v1/generations*' => Http::response(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503),
    ]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(PixelforgeUsage::class)
        ->assertActionHidden(TestAction::make('findTakes')->table('2026-11-16'))
        ->callAction(TestAction::make('findTakes')->table('2026-11-15'))
        ->assertNotified('Could not reach Pixelforge.')
        ->assertSet('findResult', null);

    Http::assertSentCount(2);
});

it('keeps the last valid range and shows an error for an invalid one', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-04', '2026-11-17', []))]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());

    Livewire::test(PixelforgeUsage::class)
        ->fillForm(['from' => '2026-01-01'])
        ->assertHasFormErrors(['from'])
        ->assertSet('rangeFrom', '2026-11-04')
        ->fillForm(['from' => null])
        ->assertHasFormErrors(['from'])
        ->fillForm(['from' => '2026-11-10'])
        ->assertHasNoFormErrors()
        ->assertSet('rangeFrom', '2026-11-10')
        ->assertCanNotSeeTableRecords(['2026-11-09']);
});

it('is hidden from an admin without the page permission', function (): void {
    $admin = User::factory()->admin(AdminRole::Auditor)->create();
    $admin->roles()->first()?->revokePermissionTo('View:PixelforgeUsage');

    $this->actingAs($admin)->get(PixelforgeUsage::getUrl())->assertForbidden();
    Http::assertNothingSent();
});

it('keeps today and later days Pending, unjudged and out of the last-good cache', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-16', '2026-11-18', []))]);

    $report = app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-18'));

    expect(array_map(fn ($day): string => $day->date.':'.$day->status->value, $report->days))
        ->toBe(['2026-11-18:pending', '2026-11-17:pending', '2026-11-16:match'])
        ->and([$report->matchedDays, $report->judgedDays])->toBe([1, 1])
        ->and(Cache::has('admin:pixelforge-usage:last:2026-11-16'))->toBeTrue()
        ->and(Cache::has('admin:pixelforge-usage:last:2026-11-17'))->toBeFalse()
        ->and(Cache::has('admin:pixelforge-usage:last:2026-11-18'))->toBeFalse();
});

it('never judges a closed day with figures fetched before it ended', function (): void {
    settledFixtureTake('2026-11-16', 'gen_a');
    Cache::put(GetPixelforgeReconciliationQuery::dayKey('2026-11-16'), [
        'fetched_at' => '2026-11-16T23:59:00+00:00',
        'totals' => ['generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_nanos' => 0],
    ], 300);
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-16', '2026-11-16', [
        '2026-11-16' => [1, 12, 765, 6450, 3184, '0.305510000'],
    ]))]);

    $day = app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-16'))->days[0];

    expect($day->status)->toBe(ReconciliationStatus::Match);
    Http::assertSentCount(1);
});

it('drops the Pixelforge total when a fallback covers only part of the range', function (): void {
    Cache::put('admin:pixelforge-usage:last:2026-11-15', [
        'fetched_at' => '2026-11-16T08:00:00+00:00',
        'totals' => ['generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_nanos' => 0],
    ], 3600);
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503)]);

    $report = app(GetPixelforgeReconciliationQuery::class)->execute(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-16'));

    expect(array_map(fn ($day): ReconciliationStatus => $day->status, $report->days))->toBe([ReconciliationStatus::Unavailable, ReconciliationStatus::Match])
        ->and($report->theirsTotal)->toBeNull();
});

it('lists our settled usage that Pixelforge did not report for the day', function (): void {
    $extra = settledFixtureTake('2026-11-15', 'gen_not_listed');
    Http::fake(['pixelforge.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null])]);

    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));

    expect(array_map(fn ($row): array => [$row->generationId, $row->takeId, $row->theirCost, $row->ourCost?->toInt()], $result->amountDiffers))
        ->toBe([['gen_not_listed', $extra->id, null, FIXTURE_COST_NANOS]]);
});

it('caches Find takes per day as plain data in a serializing store', function (): void {
    config(['cache.default' => 'database']);
    settledFixtureTake('2026-11-15', 'gen_a');
    Http::fake(['pixelforge.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [
        PixelforgeFixture::generation('succeeded', ['id' => 'gen_b', 'created_at' => '2026-11-15T10:00:00Z', 'completed_at' => '2026-11-15T10:00:00Z']),
    ], 'has_more' => false, 'next_cursor' => null])]);
    $query = app(ListPixelforgeDayDiscrepanciesQuery::class);

    $first = $query->execute(CarbonImmutable::parse('2026-11-15'));
    $second = $query->execute(CarbonImmutable::parse('2026-11-15'));

    expect(Cache::get(ListPixelforgeDayDiscrepanciesQuery::cacheKey('2026-11-15')))->toBeArray()
        ->and($second->toArray())->toBe($first->toArray())
        ->and($second->theirsNotOurs[0]->theirCost?->toInt())->toBe(FIXTURE_COST_NANOS);
    Http::assertSentCount(1);
});

it('spends one admin budget request per Find takes page, refusing only when the first page does not fit', function (): void {
    $page = fn (string $cursor): array => ['object' => 'list', 'data' => [
        PixelforgeFixture::generation('succeeded', ['id' => 'gen_'.$cursor, 'created_at' => '2026-11-15T10:00:00Z', 'completed_at' => '2026-11-15T10:00:00Z']),
    ], 'has_more' => true, 'next_cursor' => $cursor];
    Http::fake(['pixelforge.test/v1/generations*' => Http::response($page('next'))]);

    RateLimiter::increment(AdminRequestBudget::KEY, 60, AdminRequestBudget::PER_MINUTE - 3);
    $result = app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));

    expect($result->truncated)->toBeTrue()
        ->and(RateLimiter::attempts(AdminRequestBudget::KEY))->toBe(AdminRequestBudget::PER_MINUTE);
    Http::assertSentCount(3);

    expect(fn () => app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-14')))
        ->toThrow(AdminRequestBudgetExhaustedException::class);
    Http::assertSentCount(3);
});

it('never drives the budget counter negative when a scan crosses the window', function (): void {
    $sent = 0;
    Http::fake(['pixelforge.test/v1/generations*' => function () use (&$sent) {
        $sent++;

        if ($sent === 2) {
            $this->travel(61)->seconds(); // the window that charged pages 1-2 expires mid-scan
        }

        return Http::response(['object' => 'list', 'data' => [
            PixelforgeFixture::generation('succeeded', ['id' => 'gen_'.$sent, 'created_at' => '2026-11-15T10:00:00Z', 'completed_at' => '2026-11-15T10:00:00Z']),
        ], 'has_more' => $sent < 4, 'next_cursor' => $sent < 4 ? 'c'.$sent : null]);
    }]);

    app(ListPixelforgeDayDiscrepanciesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));

    // Pages 3 and 4 were charged to the new window; nothing was refunded into it.
    expect(RateLimiter::attempts(AdminRequestBudget::KEY))->toBe(2)
        ->and(AdminRequestBudget::remaining())->toBe(AdminRequestBudget::PER_MINUTE - 2);
});

it('serves the last good figures when the budget is spent, and does not retry a failed fetch for a minute', function (): void {
    $query = app(GetPixelforgeReconciliationQuery::class);
    $from = CarbonImmutable::parse('2026-11-15');
    $to = CarbonImmutable::parse('2026-11-16');
    Http::fakeSequence('pixelforge.test/v1/usage*')
        ->push(usagePayload('2026-11-15', '2026-11-16', []))
        ->push(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503)
        ->push(usagePayload('2026-11-15', '2026-11-16', []));

    $query->execute($from, $to);
    RateLimiter::increment(AdminRequestBudget::KEY, 60, AdminRequestBudget::PER_MINUTE);
    $throttled = $query->execute($from, $to, refresh: true);

    expect([$throttled->throttled, $throttled->unavailable, $throttled->days[0]->status])->toBe([true, true, ReconciliationStatus::Match]);
    Http::assertSentCount(1);

    RateLimiter::clear(AdminRequestBudget::KEY);
    $query->execute($from, $to, refresh: true); // 503, remembered for a minute
    $query->execute($from, $to, refresh: true); // served from last-good, no call
    Http::assertSentCount(2);

    $this->travel(61)->seconds();
    expect($query->execute($from, $to, refresh: true)->unavailable)->toBeFalse();
    Http::assertSentCount(3);
});

it('notifies instead of refreshing or finding when the admin budget is spent', function (): void {
    settledFixtureTake('2026-11-15', 'gen_a');
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-04', '2026-11-17', [
        '2026-11-15' => [2, 24, 1530, 12900, 6368, '0.611020000'],
    ]))]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
    $page = Livewire::test(PixelforgeUsage::class);
    RateLimiter::increment(AdminRequestBudget::KEY, 60, AdminRequestBudget::PER_MINUTE);

    $page->callAction('refresh')->assertNotified()
        ->callAction(TestAction::make('findTakes')->table('2026-11-15'))->assertNotified()
        ->assertSet('findResult', null);
    Http::assertSentCount(1);
});

it('locks the applied range and the drill-down result against client writes, and errors per field', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-04', '2026-11-17', []))]);
    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
    $page = Livewire::test(PixelforgeUsage::class);

    expect(fn () => $page->set('rangeFrom', 'not-a-date'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $page->set('findResult', ['date' => 'x']))->toThrow(CannotUpdateLockedPropertyException::class);

    $page->fillForm(['from' => null, 'until' => '2026-11-16'])->assertHasFormErrors(['from'])->assertHasNoFormErrors(['until']);
});

it('offers Reconcile now on drill-down rows of open takes to operators, never on orphan rows', function (): void {
    Queue::fake();
    Event::fake([TakeUpdated::class]);
    $open = Takes::in('processing');
    $orphan = Take::factory()->failed()->create(['pixelforge_id' => 'gen_orphan_row']);
    settledFixtureTake('2026-11-15', 'gen_a');
    Http::fake([
        'pixelforge.test/v1/usage*' => Http::response(usagePayload('2026-11-04', '2026-11-17', [
            '2026-11-15' => [3, 36, 2295, 19350, 9552, '0.916530000'],
        ])),
        'pixelforge.test/v1/generations?*' => Http::response(['object' => 'list', 'data' => [
            PixelforgeFixture::generation('succeeded', ['id' => $open->pixelforge_id, 'created_at' => '2026-11-15T10:00:00Z', 'completed_at' => '2026-11-15T10:00:00Z']),
            PixelforgeFixture::generation('succeeded', ['id' => 'gen_orphan_row', 'created_at' => '2026-11-15T10:00:00Z', 'completed_at' => '2026-11-15T10:00:00Z']),
        ], 'has_more' => false, 'next_cursor' => null]),
        'pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixture::generation('succeeded', [
            'id' => $open->pixelforge_id, 'metadata' => ['app_take_id' => $open->id],
        ])),
    ]);
    $this->actingAs(User::factory()->admin(AdminRole::Operator)->withAppAuthentication()->create());

    $page = Livewire::test(PixelforgeUsage::class)->callAction(TestAction::make('findTakes')->table('2026-11-15'));
    $findResult = $page->get('findResult');
    $keyOf = function (string $group, string $generation) use ($findResult): string {
        $index = collect($findResult['groups'][$group])->search(fn (array $row): bool => $row['generation'] === $generation);
        expect($index)->not->toBeFalse();

        return $group.'-'.$index;
    };
    $openKey = $keyOf('ours_not_settled', (string) $open->pixelforge_id);
    $orphanKey = $keyOf('orphan_success', 'gen_orphan_row');

    Livewire::test(PixelforgeDiscrepancies::class, ['findResult' => $findResult])
        ->assertSeeHtml(TakeResource::getUrl('view', ['record' => $open->id]))
        ->assertActionVisible(TestAction::make('reconcile')->table($openKey))
        ->assertActionHidden(TestAction::make('reconcile')->table($orphanKey))
        ->callAction(TestAction::make('reconcile')->table($openKey))
        ->assertNotified();

    expect($open->refresh()->status)->toBe(TakeStatus::Succeeded)
        ->and(Activity::query()->where('event', 'reconciled')->where('subject_id', $open->id)->exists())->toBeTrue();

    $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create());
    Livewire::test(PixelforgeDiscrepancies::class, ['findResult' => $findResult])
        ->assertActionHidden(TestAction::make('reconcile')->table($openKey));
});

it('renders the page over HTTP as native Filament components, with the outage as a callout', function (): void {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['error' => ['type' => 'api_error', 'code' => 'internal_error', 'message' => 'down']], 503)]);

    $html = (string) $this->actingAs(User::factory()->admin(AdminRole::Auditor)->create())
        ->get(PixelforgeUsage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('fi-ta-table')
        ->toContain('fi-callout')
        ->toContain('Could not reach Pixelforge.');
});
