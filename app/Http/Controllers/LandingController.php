<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Takes\EstimateTakeDTO;
use App\Enums\Takes\TakeQuality;
use App\Enums\Takes\TakeSize;
use App\Queries\Takes\EstimateTakeQuery;
use App\Support\Money\Nanodollars;
use App\Support\Money\RoundingMode;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page (P01, spec 4 issue 8): the welcome credit from
 * WELCOME_CREDIT_USD (D-019), example price ranges computed by the same
 * estimator the composer uses (prices, never costs or the margin, D-023),
 * and "Try a demo account" when DEMO_LOGIN_ENABLED (D-045). The examples
 * read the cached pricing or the config fallback, never GET /v1/pricing:
 * an anonymous visitor never waits on the provider.
 */
final class LandingController extends Controller
{
    public function __invoke(EstimateTakeQuery $estimateTake): Response
    {
        return Inertia::render('Welcome', [
            'welcomeCredit' => Nanodollars::fromInt(config()->integer('takes.welcome_credit_nanos'))->formatExact(),
            'demo' => [
                'enabled' => config()->boolean('takes.demo.enabled'),
            ],
            'pricingExamples' => [
                $this->example($estimateTake, __('landing.pricing.square_medium_short'), new EstimateTakeDTO(24, TakeSize::Square, TakeQuality::Medium, 0)),
                $this->example($estimateTake, __('landing.pricing.landscape_high_two_refs'), new EstimateTakeDTO(400, TakeSize::Landscape, TakeQuality::High, 2)),
            ],
        ]);
    }

    /**
     * @return array{label: string, range: string}
     */
    private function example(EstimateTakeQuery $estimateTake, string $label, EstimateTakeDTO $input): array
    {
        $estimate = $estimateTake->execute($input, cachedPricing: true)->estimate;

        return [
            'label' => $label,
            'range' => __('takes.estimate.range', [
                'min' => $estimate->minCharge->format(4, RoundingMode::Floor),
                'max' => $estimate->maxCharge->format(4, RoundingMode::Ceil),
            ]),
        ];
    }
}
