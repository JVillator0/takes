<?php

declare(strict_types=1);

use App\Enums\Suggestions\SuggestionStatus;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Suggestions;

/*
 * The P07 endpoints under the customer guard (D-032): JSON for the panel's
 * standalone requests, denials as DenialReason codes with 2-01 copy, prices
 * only (D-023).
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config([
        'takes.ai.enabled' => true,
        'takes.ai.daily_budget_nanos' => 1_000_000_000,
        'takes.ai.daily_limit_per_customer' => 30,
        'takes.ai.throttle_per_minute' => 6,
    ]);
    RateLimiter::clear('ai-suggestions');

    $this->customer = Suggestions::customer(1_000_000_000);
    $this->take = Suggestions::baseTake($this->customer);
});

/** @param  array<string, mixed>  $overrides */
function postSuggestion(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->customer, 'customer')->postJson(route('suggestions.store', test()->take), [
        'instruction' => 'Make it portrait, golden hour',
        'request_key' => (string) Str::uuid(),
        ...$overrides,
    ]);
}

/** Words a customer must never read (D-023, M-23). */
function expectNoProviderLeak(TestResponse $response): void
{
    $body = strtolower((string) $response->getContent());

    foreach (['anthropic', 'claude', 'haiku', 'pixelforge', 'token', 'markup', 'margin', 'provider_cost', '254000', 'msg_'] as $word) {
        expect($body)->not->toContain($word);
    }
}

it('requires a signed-in customer', function (): void {
    $this->postJson(route('suggestions.store', $this->take), ['instruction' => 'x', 'request_key' => (string) Str::uuid()])
        ->assertUnauthorized();
});

it('returns the reviewed diff with the exact suggestion charge and the new take price', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));

    $response = postSuggestion()->assertOk();

    $response->assertJson([
        'status' => 'proposed',
        'instruction' => 'Make it portrait, golden hour',
        'rationale' => Suggestions::proposal()['rationale'],
        'noChange' => false,
        'message' => null,
        'hold' => null,
        'charge' => [
            'exact' => '$0.0003175',
            'disclosure' => 'Suggestion cost $0.0003175. Charged even if you discard.',
        ],
        'diff' => [
            'prompt' => ['changed' => true],
            'rows' => [
                ['key' => 'size', 'label' => 'Size', 'before' => 'Square 1024×1024', 'after' => 'Portrait 1024×1536', 'changed' => true],
                ['key' => 'quality', 'label' => 'Quality', 'before' => 'Medium', 'after' => 'High', 'changed' => true],
                ['key' => 'references', 'label' => 'References', 'before' => '1', 'after' => '1 + take image', 'changed' => true],
            ],
        ],
        'newTake' => ['canAfford' => true],
        'resultingTakeId' => null,
    ]);

    expect($response->json('newTake.range'))->toMatch('/^New take: \$\d+\.\d{4} – \$\d+\.\d{4}$/')
        ->and($response->json('newTake.generate'))->toMatch('/^Generate take · up to \$\d+\.\d{4}$/')
        ->and(collect($response->json('diff.prompt.segments'))->pluck('type')->unique()->sort()->values()->all())->toBe(['added', 'removed', 'same']);

    expectNoProviderLeak($response);
});

it('flags an unaffordable new take (Buy credits instead of Generate take)', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    $this->customer = Suggestions::customer(1_000_000);
    $this->take = Suggestions::baseTake($this->customer);

    postSuggestion()->assertOk()->assertJsonPath('newTake.canAfford', false);
});

it('shows the 2-01 copy for each outcome', function (Closure $fake, ?string $message, string $status): void {
    $fake();

    postSuggestion(['instruction' => 'make it nicer'])->assertOk()
        ->assertJsonPath('status', $status)
        ->assertJsonPath('message', $message);
})->with([
    'refusal' => [fn () => Suggestions::fake(Suggestions::messageBody(null, stopReason: 'refusal')), 'Suggestion failed. You were not charged.', 'failed'],
    'timeout' => [fn () => Suggestions::fake(fn () => throw new ConnectionException('timed out')), 'Describe a change is unavailable right now. You were not charged.', 'failed'],
    'no change' => [fn () => Suggestions::fake(Suggestions::messageBody(Suggestions::proposal([
        'prompt' => Suggestions::BASE_PROMPT, 'size' => '1024x1024', 'quality' => 'medium', 'add_base_image_as_reference' => false,
    ]))), 'Nothing to change for that request. Try describing a different change. The suggestion cost is charged.', 'proposed'],
]);

it('validates the instruction and the request key', function (array $payload, string $field): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));

    postSuggestion($payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'too long' => [['instruction' => str_repeat('a', 501)], 'instruction'],
    'empty' => [['instruction' => ''], 'instruction'],
    'bad key' => [['request_key' => 'not-a-uuid'], 'request_key'],
]);

it('hides another customer\'s take and suggestion (404)', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    $other = Customer::factory()->withRole()->create();
    $suggestion = Suggestion::factory()->proposed()->create(['customer_id' => $this->customer->id, 'project_id' => $this->take->project_id, 'base_take_id' => $this->take->id]);

    $this->actingAs($other, 'customer')->postJson(route('suggestions.store', $this->take), ['instruction' => 'x', 'request_key' => (string) Str::uuid()])->assertNotFound();
    $this->actingAs($other, 'customer')->getJson(route('suggestions.show', $suggestion))->assertNotFound();
    $this->actingAs($other, 'customer')->postJson(route('suggestions.apply', $suggestion))->assertNotFound();
    $this->actingAs($other, 'customer')->getJson(route('suggestions.quote', $this->take))->assertNotFound();
});

it('denies with the DenialReason code and its copy', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    config(['takes.ai.enabled' => false]);

    postSuggestion()->assertForbidden()->assertExactJson([
        'reason' => 'ai_paused',
        'message' => 'Describe a change is paused right now. You can still generate takes.',
    ]);
});

it('rejects a second suggestion while one is pending on the take', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    Suggestion::factory()->create(['customer_id' => $this->customer->id, 'project_id' => $this->take->project_id, 'base_take_id' => $this->take->id]);

    postSuggestion()->assertConflict()->assertExactJson([
        'reason' => 'already_pending',
        'message' => 'A suggestion for this take is already in progress. Wait for it to finish.',
    ]);
});

it('throttles to 6 requests per minute per customer (rate_limited)', function (): void {
    Suggestions::fake(fn () => throw new ConnectionException('timed out'));

    foreach (range(1, 6) as $ignored) {
        postSuggestion()->assertOk();
    }

    postSuggestion()->assertTooManyRequests()->assertJson(['reason' => 'rate_limited', 'message' => 'You are going too fast. Try again in a moment.']);
});

it('quotes the worst-case hold before the customer types, and the denial if any', function (): void {
    $response = $this->actingAs($this->customer, 'customer')->getJson(route('suggestions.quote', $this->take))->assertOk();

    expect($response->json('quote'))->toMatch('/^AI suggestion · up to \$\d+\.\d{4} on hold$/')
        ->and($response->json('denial'))->toBeNull();

    config(['takes.ai.enabled' => false]);

    $this->actingAs($this->customer, 'customer')->getJson(route('suggestions.quote', $this->take))
        ->assertJsonPath('denial.reason', 'ai_paused');
});

it('shows, discards and refuses to apply until take creation is bound', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    $id = postSuggestion()->json('id');
    $suggestion = Suggestion::query()->findOrFail($id);

    $this->actingAs($this->customer, 'customer')->getJson(route('suggestions.show', $suggestion))->assertOk()->assertJsonPath('status', 'proposed');

    $this->actingAs($this->customer, 'customer')->postJson(route('suggestions.apply', $suggestion))
        ->assertStatus(503)
        ->assertJsonPath('reason', 'apply_unavailable');

    $this->actingAs($this->customer, 'customer')->postJson(route('suggestions.discard', $suggestion))
        ->assertOk()
        ->assertJsonPath('status', 'discarded')
        ->assertJsonPath('charge.exact', '$0.0003175');

    expect($suggestion->refresh()->status)->toBe(SuggestionStatus::Discarded);
});

it('answers base_not_ready and a reused request key with 409 and their copy', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    $key = (string) Str::uuid();
    postSuggestion(['request_key' => $key])->assertOk();

    $this->take = Suggestions::baseTake($this->customer, ['project_id' => $this->take->project_id]);
    postSuggestion(['request_key' => $key])->assertConflict()->assertExactJson([
        'reason' => 'request_key_reused',
        'message' => 'This request was already sent for another take. Try again.',
    ]);

    $this->take = Take::factory()->processing()->create(['customer_id' => $this->customer->id]);
    postSuggestion()->assertConflict()->assertExactJson([
        'reason' => 'base_not_ready',
        'message' => 'Describe a change works on finished takes.',
    ]);
});

it('never tells the customer a charged suggestion was not charged when it can no longer be used', function (): void {
    $suggestion = Suggestion::factory()->create([
        'customer_id' => $this->customer->id,
        'project_id' => $this->take->project_id,
        'base_take_id' => $this->take->id,
        'status' => SuggestionStatus::Failed,
    ]);

    foreach (['suggestions.apply', 'suggestions.discard'] as $route) {
        $response = $this->actingAs($this->customer, 'customer')->postJson(route($route, $suggestion))->assertConflict();

        expect($response->json())->toBe(['reason' => 'not_proposed', 'message' => 'This suggestion can no longer be used. Describe a new change.'])
            ->and($response->json('message'))->not->toContain('not charged');
    }
});

it('marks an unavailable base image on the references row and refuses apply with the 2-01 reason', function (): void {
    Suggestions::fake(Suggestions::messageBody(Suggestions::proposal()));
    $this->take->forceFill(['image_path' => null, 'image_url_expires_at' => now()->subMinute()])->save();

    $response = postSuggestion()->assertOk()
        ->assertJsonPath('baseImageUnavailable', true)
        ->assertJsonPath('diff.rows.2.after', '1')
        ->assertJsonPath('diff.rows.2.note', 'This image is no longer available');

    $this->actingAs($this->customer, 'customer')->postJson(route('suggestions.apply', $response->json('id')))
        ->assertConflict()
        ->assertExactJson(['reason' => 'image_unavailable', 'message' => 'This image is no longer available']);
});
