<?php

declare(strict_types=1);

use App\Enums\Auth\DenialReason;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Models\Customer;
use App\Support\Pricing\TakeEstimator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Tests\Support\Billing;
use Tests\Support\TakeFlow;

/*
| `GET /estimates/take` (spec 4 "Estimate"): the one TakeEstimator, fed a
| character count, never the prompt; strings only (M-20), exact plus the
| 4-decimal display (D-021, D-042); the policy answer for this estimate.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    TakeFlow::configure();
    TakeFlow::fakePixelforge();
});

function estimateFor(Customer $customer, array $query): TestResponse
{
    return test()->actingAs($customer, 'customer')->getJson(route('estimates.take', $query));
}

it('matches the estimator on the docs worked example and sheet A06', function (array $query, string $min, string $max, string $minDisplay, string $maxDisplay): void {
    estimateFor(TakeFlow::customer(), $query)
        ->assertOk()
        ->assertExactJson([
            'min' => $min,
            'max' => $max,
            'min_display' => $minDisplay,
            'max_display' => $maxDisplay,
            'references' => $query['references'],
            'can' => ['allowed' => true, 'reason' => null, 'message' => null],
        ]);
})->with([
    // 400 chars, high, 1536×1024, 2 references: cost 373,875,000 (spec 3-03 §4).
    'worked example' => [['chars' => 400, 'size' => '1536x1024', 'quality' => 'high', 'references' => 2], '$0.35778125', '$0.46734375', '$0.3577', '$0.4674'],
    // A06: 64 chars, square, medium, 1 reference ⇒ "$0.0677 – $0.0960".
    'A06' => [['chars' => 64, 'size' => '1024x1024', 'quality' => 'medium', 'references' => 1], '$0.06776875', '$0.09593125', '$0.0677', '$0.0960'],
]);

it('agrees with TakeEstimator for every size and quality', function (TakeSize $size, TakeQuality $quality): void {
    $expected = app(TakeEstimator::class)->estimateForCharacters(120, $size, $quality, 1, Billing::pixelforgeRates()->toArray());

    estimateFor(TakeFlow::customer(), ['chars' => 120, 'size' => $size->value, 'quality' => $quality->value, 'references' => 1])
        ->assertJsonPath('min', $expected->minCharge->formatExact())
        ->assertJsonPath('max', $expected->maxCharge->formatExact());
})->with(TakeSize::cases())->with(TakeQuality::cases());

it('counts Unicode characters the way the server does (emoji, accents, CJK)', function (string $prompt): void {
    $chars = mb_strlen($prompt, 'UTF-8');
    $expected = app(TakeEstimator::class)->estimate($prompt, TakeSize::Square, TakeQuality::Low, 0, Billing::pixelforgeRates()->toArray());

    estimateFor(TakeFlow::customer(), ['chars' => $chars, 'size' => '1024x1024', 'quality' => 'low', 'references' => 0])
        ->assertJsonPath('max', $expected->maxCharge->formatExact());
})->with([
    'emoji' => '🌊🌊🌊 lighthouse 🏝️',
    'accents' => 'Café au lait, crème brûlée',
    'CJK' => '灯台と夕焼け、映画のような広角',
]);

it('never receives the prompt text', function (): void {
    estimateFor(TakeFlow::customer(), ['chars' => 10, 'size' => '1024x1024', 'quality' => 'low', 'prompt' => 'secret'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('prompt');
});

it('validates the inputs', function (array $query, string $field): void {
    estimateFor(TakeFlow::customer(), [...['chars' => 10, 'size' => '1024x1024', 'quality' => 'low', 'references' => 0], ...$query])
        ->assertJsonValidationErrors($field);
})->with([
    'too many characters' => [['chars' => 4001], 'chars'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many references' => [['references' => 5], 'references'],
]);

it('says insufficient_credits when the max is above the available credits', function (): void {
    estimateFor(TakeFlow::customer(10_000_000), ['chars' => 400, 'size' => '1536x1024', 'quality' => 'high', 'references' => 2])
        ->assertOk()
        ->assertJsonPath('can.allowed', false)
        ->assertJsonPath('can.reason', DenialReason::InsufficientCredits->value)
        ->assertJsonPath('can.message', DenialReason::InsufficientCredits->message());
});

it('needs a signed-in customer', function (): void {
    $this->getJson(route('estimates.take', ['chars' => 1, 'size' => '1024x1024', 'quality' => 'low']))->assertUnauthorized();
});
