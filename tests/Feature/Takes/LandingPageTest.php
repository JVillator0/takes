<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('renders the landing page with the demo button off by default', function (): void {
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

it('never shows the margin or a provider name in customer copy', function (): void {
    $copy = json_encode([trans('takes'), trans('billing'), trans('landing')], JSON_THROW_ON_ERROR);

    expect($copy)
        ->not->toContain('25%')
        ->not->toContain('Pixelforge')
        ->not->toContain('Anthropic');
});
