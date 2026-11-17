<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the landing page with the demo button off by default', function (): void {
    config()->set('takes.welcome_credit_nanos', 1_000_000_000);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('welcomeCredit', '$1.00')
            ->where('demo.enabled', false)
            ->where('pricingExamples.0.label', 'Square · medium · short prompt')
            ->where('pricingExamples.0.range', '$0.0581 – $0.0863')
        );
});

it('shares the takes and billing copy with the client', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('copy.takes.status.waiting_for_capacity', 'Waiting for capacity')
            ->where('copy.takes.failure.content_policy', 'This prompt was declined by the content policy. Change it and try again. You were not charged.')
            ->where('copy.billing.simulated_notice', 'This is a simulated payment for the take-home. No card, nothing is charged.')
            ->where('copy.landing.hero.demo', 'Try a demo account')
        );
});

it('never shows the margin, markup or a provider name in customer copy', function (): void {
    $copy = json_encode([trans('takes'), trans('billing'), trans('landing')], JSON_THROW_ON_ERROR);

    expect($copy)->not->toMatch('/pixelforge|anthropic|markup|margin|25\s*%/i');
});

it('re-sends the copy only when the client does not hold the current version', function (): void {
    $version = app(HandleInertiaRequests::class)->version(request());
    $inertiaVisit = fn (string $loadedOnceProps) => $this->get(route('home'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Except-Once-Props' => $loadedOnceProps,
    ]);

    $current = 'copy:'.HandleInertiaRequests::copyVersion();

    expect($inertiaVisit($current)->json('props'))->not->toHaveKey('copy');
    expect($inertiaVisit('copy:stale-hash')->json('props'))->toHaveKey('copy');
});

it('advertises the configured welcome credit', function (): void {
    config()->set('takes.welcome_credit_nanos', 2_500_000_000);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('welcomeCredit', '$2.50'));
});
