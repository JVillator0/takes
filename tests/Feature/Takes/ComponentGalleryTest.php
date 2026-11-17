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
        );
});

it('is not reachable in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/_ui')->assertNotFound();
});
