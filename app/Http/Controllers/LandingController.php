<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Money\Nanodollars;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page (P01). Presentational, with its remaining placeholders named:
 * - `welcomeCredit` is the configured grant (`takes.welcome_credit_nanos`,
 *   WELCOME_CREDIT_USD, D-019) formatted exactly, e.g. "$1.00" (D-042);
 * - the example ranges become TakeEstimator output (spec 4 issue #53);
 * - the demo button (POST /demo) is turned on by spec 4 issue #46.
 */
class LandingController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'welcomeCredit' => Nanodollars::fromInt(config()->integer('takes.welcome_credit_nanos'))->formatExact(),
            'demo' => [
                'enabled' => false,
                'action' => null,
            ],
            'pricingExamples' => [
                [
                    'label' => __('landing.pricing.square_medium_short'),
                    'range' => __('takes.estimate.range', ['min' => '$0.0581', 'max' => '$0.0863']),
                ],
                [
                    'label' => __('landing.pricing.landscape_high_two_refs'),
                    'range' => __('takes.estimate.range', ['min' => '$0.3577', 'max' => '$0.4674']),
                ],
            ],
        ]);
    }
}
