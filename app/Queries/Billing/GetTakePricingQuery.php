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
 * or the config fallback instead. A public page that must never wait on
 * the provider (the landing page) asks for that same cache-or-fallback read.
 *
 * The check is the real transaction level (=== 0), not TransactionBaseline:
 * this query decides whether an HTTP call may happen at all, and inside
 * RefreshDatabase the Feature suite must keep using the cache or fallback
 * rather than call the provider. The provider branch is covered at level 0
 * in tests/Committed (CreateTakePricingTest, LandingPricingTest).
 */
final class GetTakePricingQuery
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @param  bool  $cachedOnly  never call the provider: the cached pricing, else the config fallback
     */
    public function execute(bool $cachedOnly = false): PricingDTO
    {
        if (! $cachedOnly && DB::transactionLevel() === 0) {
            return $this->client->getPricing();
        }

        return $this->cachedOrFallback();
    }

    private function cachedOrFallback(): PricingDTO
    {
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
