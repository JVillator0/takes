<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('renders every take state in the gallery with copy from the lang files', function (): void {
    $this->get(route('dev.gallery'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dev/ComponentGallery')
            ->has('takes', 8)
            ->where('takes.0.statusLabel', 'Waiting for capacity')
            ->where('takes.0.moneyLine', 'Up to $0.1139 on hold. Not charged yet.')
            ->where('takes.3.moneyLine', 'Charged $0.0740')
            ->where('takes.4.failureMessage', 'The image could not be generated. You were not charged.')
            ->where('details.done.price.amount', '$0.0740375')
            ->where('insufficientMessage', 'Your available credits are below the max price. Buy credits to continue.')
            ->where('describeChange.proposed.charge.disclosure', 'Suggestion cost $0.0003175. Charged even if you discard.')
            ->where('describeChange.failed.message', 'Suggestion failed. You were not charged.')
            ->where('denialMessages.daily_take_cap', "You reached today's take limit. It resets at 00:00 UTC.")
        );
});

it('resolves every copy key in the gallery payload', function (): void {
    $props = $this->get(route('dev.gallery'))->viewData('page')['props'];

    // Unanchored: a missing key nested in another string ("estimate was
    // takes.estimate.range") is caught too.
    $unresolved = [];
    array_walk_recursive($props, function (mixed $value) use (&$unresolved): void {
        if (is_string($value) && preg_match_all('/\b(?:takes|billing|landing|denials|suggestions)(?:\.[a-z0-9_]+)+/', $value, $matches) > 0) {
            array_push($unresolved, ...$matches[0]);
        }
    });

    expect($unresolved)->toBe([]);
});

it('is not reachable in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/_ui')->assertNotFound();
});
