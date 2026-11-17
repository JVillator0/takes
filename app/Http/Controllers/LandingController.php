<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page (P01). Presentational only: spec 4 issue #53 replaces the
 * example ranges with TakeEstimator output, and issue #46 turns on the demo
 * button (POST /demo) when demo logins are enabled.
 */
class LandingController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'welcomeCredit' => '$1.00',
            'demo' => [
                'enabled' => false,
                'action' => null,
            ],
            'pricingExamples' => [
                [
                    'label' => 'Square · medium · short prompt',
                    'range' => __('takes.estimate.range', ['min' => '$0.0581', 'max' => '$0.0863']),
                ],
                [
                    'label' => 'Landscape · high · 2 references',
                    'range' => __('takes.estimate.range', ['min' => '$0.3577', 'max' => '$0.4674']),
                ],
            ],
        ]);
    }
}
