<?php

declare(strict_types=1);

namespace App\Http\Controllers\Takes;

use App\DTOs\Auth\AbilityDTO;
use App\DTOs\Takes\EstimateTakeDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Takes\EstimateTakeRequest;
use App\Models\Take;
use App\Queries\Takes\EstimateTakeQuery;
use App\Support\Money\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /estimates/take` (spec 4 "Estimate"): the price range for the
 * composer, as strings (M-20). `min`/`max` are exact (D-042) and
 * `min_display`/`max_display` are the 4-decimal display (min rounded down,
 * max rounded up, D-021). `can` is TakePolicy::create with this estimate,
 * so the composer shows `insufficient_credits` and the caps before submit
 * (spec 3-01 "can" props); the action re-checks under lock.
 */
final class EstimateController extends Controller
{
    public function __invoke(EstimateTakeRequest $request, EstimateTakeQuery $estimateTake): JsonResponse
    {
        $estimate = $estimateTake->execute(EstimateTakeDTO::fromRequest($request))->estimate;

        return new JsonResponse([
            'min' => $estimate->minCharge->formatExact(),
            'max' => $estimate->maxCharge->formatExact(),
            'min_display' => $estimate->minCharge->format(4, RoundingMode::Floor),
            'max_display' => $estimate->maxCharge->format(4, RoundingMode::Ceil),
            'references' => $request->integer('references'),
            'can' => AbilityDTO::fromResponse(
                Gate::forUser($request->customer())->inspect('create', [Take::class, $estimate]),
            )->toArray(),
        ]);
    }
}
