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
 * A model must configure exactly its provider's token kinds, so a typo fails
 * here, before a paid call, not at settlement.
 */
final readonly class AiPricing
{
    /** Token kinds each configurable provider bills (spec 3-03 §2: no cache kinds until used). */
    public const array KINDS = [
        'anthropic' => ['input_text', 'output'],
    ];

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

        $kinds = self::KINDS[$provider->value] ?? null;

        if ($kinds === null || ! is_array($configured) || $configured === []) {
            throw new InvalidArgumentException("No rates configured for [{$provider->value}] model [{$model}].");
        }

        $configuredKinds = array_map(strval(...), array_keys($configured));
        $missing = array_diff($kinds, $configuredKinds);
        $unknown = array_diff($configuredKinds, $kinds);

        if ($missing !== [] || $unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Rates for [%s] model [%s] must have exactly the kinds [%s]; missing [%s], unknown [%s].',
                $provider->value,
                $model,
                implode(', ', $kinds),
                implode(', ', $missing),
                implode(', ', $unknown),
            ));
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
