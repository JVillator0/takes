<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Enums\Billing\Provider;
use App\Support\Money\Nanodollars;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Turns the decimal-string rates in config/ai-pricing.php into a rates
 * snapshot: integer nanodollars per 1M tokens by kind (spec 3-03 §2), the
 * shape stored in `usage_records.rates`. Haiku 5.5 '0.10' ⇒ 100_000_000.
 */
final readonly class AiPricing
{
    public function __construct(private Repository $config) {}

    /**
     * @return array<string, int> nanodollars per 1M tokens by kind
     */
    public function rates(Provider $provider, string $model): array
    {
        $providers = $this->config->get('ai-pricing');
        $configured = is_array($providers) && is_array($providers[$provider->value] ?? null)
            ? ($providers[$provider->value][$model] ?? null)
            : null;

        if (! is_array($configured) || $configured === []) {
            throw new InvalidArgumentException("No rates configured for [{$provider->value}] model [{$model}].");
        }

        $rates = [];

        foreach ($configured as $kind => $ratePerMillion) {
            if (! is_string($ratePerMillion)) {
                throw new InvalidArgumentException("Rate [{$provider->value}.{$model}.{$kind}] must be a decimal string.");
            }

            $rates[(string) $kind] = Nanodollars::perMillionFromDecimalString($ratePerMillion)->toInt();
        }

        return $rates;
    }
}
