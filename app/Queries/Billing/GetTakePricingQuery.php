<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Pixelforge\PricingDTO;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The rates and token tables a take estimate uses (spec 4 "Estimate"):
 * GET /v1/pricing, cached for an hour, with the config fallback.
 *
 * Never makes an HTTP call inside a database transaction (M-16): when a
 * caller already holds one (spec 8 applies a suggestion through
 * CreateTakeAction inside its own transaction), it reads the cached pricing
 * or the config fallback instead.
 */
final class GetTakePricingQuery
{
    public function __construct(private readonly PixelforgeClient $client) {}

    public function execute(): PricingDTO
    {
        if (DB::transactionLevel() === 0) {
            return $this->client->getPricing();
        }

        $cached = Cache::get(PixelforgeClient::PRICING_CACHE_KEY);

        if (is_array($cached)) {
            try {
                return PricingDTO::fromArray($cached);
            } catch (InvalidArgumentException) {
                // A bad cache entry: the fallback below is always valid.
            }
        }

        return PricingDTO::fromArray(config()->array('pixelforge.pricing.fallback'));
    }
}
