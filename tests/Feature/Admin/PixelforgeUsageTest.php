<?php

declare(strict_types=1);

use App\Enums\Auth\AdminRole;
use App\Enums\Billing\ReconciliationStatus;
use App\Filament\Admin\Clusters\Providers\Pages\PixelforgeUsage;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Models\User;
use App\Queries\Billing\FindPixelforgeTakesQuery;
use App\Queries\Billing\GetPixelforgeReconciliationQuery;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\PixelforgeFixture;

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

it('finds the four groups of a mismatching day across provider pages', function (): void {
    settledFixtureTake('2026-11-15', 'gen_ok');
    settledFixtureTake('2026-11-15', 'gen_differs', costNanos: 300_000_000);
    $unsettled = Take::factory()->processing()->create(['pixelforge_id' => 'gen_unsettled']);
    UsageRecord::factory()->create(['customer_id' => $unsettled->customer_id, 'billable_id' => $unsettled->id]);
    $orphan = Take::factory()->failed()->create(['pixelforge_id' => 'gen_orphan']);
    $done = fn (string $id, string $completedAt = '2026-11-15T10:00:00Z'): array => PixelforgeFixture::generation('succeeded', ['id' => $id, 'completed_at' => $completedAt]);

    Http::fakeSequence('pixelforge.test/v1/generations*')
        ->push(['object' => 'list', 'data' => [$done('gen_ok'), $done('gen_unknown'), $done('gen_other_day', '2026-11-14T23:59:59Z')], 'has_more' => true, 'next_cursor' => 'cur_2'])
        ->push(['object' => 'list', 'data' => [$done('gen_differs'), $done('gen_unsettled'), $done('gen_orphan')], 'has_more' => false, 'next_cursor' => null]);

    $result = app(FindPixelforgeTakesQuery::class)->execute(CarbonImmutable::parse('2026-11-15'));
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

    Livewire::test(PixelforgeUsage::class)
        ->assertOk()
        ->assertSee('0.305510000 / 0.611020000')
        ->assertSee('12 / 13 days match')
        ->assertSee('Mismatch')
        ->call('findTakes', '2026-11-15')
        ->assertSee('Theirs, not ours (1)')
        ->assertSee('gen_b');
});

it('is hidden from an admin without the page permission', function (): void {
    $admin = User::factory()->admin(AdminRole::Auditor)->create();
    $admin->roles()->first()?->revokePermissionTo('View:PixelforgeUsage');

    $this->actingAs($admin)->get(PixelforgeUsage::getUrl())->assertForbidden();
    Http::assertNothingSent();
});
