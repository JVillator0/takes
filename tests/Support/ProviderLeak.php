<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * M-23, D-023: what a customer receives must not name the provider or carry
 * cost, tokens, rates or the margin. Scans keys and string values; image
 * URLs are skipped because the provider's signed URL is the documented
 * fallback until the persisted copy exists (spec 4 "Persist image"): a value
 * under a key ending in `url`, and each entry of a list of image URLs (a key
 * ending in `images`, e.g. the drawer's referenceImages). Their keys are
 * still scanned.
 */
final class ProviderLeak
{
    public const array FORBIDDEN_KEYS = ['cost', 'token', 'rate', 'margin', 'markup', 'provider', 'pixelforge', 'usage', 'nanos', 'units', 'idempotency', 'failure_message', 'attention'];

    public const array FORBIDDEN_WORDS = ['pixelforge', 'anthropic', 'provider', 'token', 'margin', 'markup', 'gen_', 'nanos', '25%'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string> the offending paths
     */
    public static function find(array $data, string $path = '', bool $imageUrls = false): array
    {
        $found = [];

        foreach ($data as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";
            $lowerKey = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                if (! is_int($key) && str_contains($lowerKey, $forbidden)) {
                    $found[] = "key {$here}";
                }
            }

            if (is_array($value)) {
                $found = [...$found, ...self::find($value, $here, str_ends_with($lowerKey, 'images'))];
            } elseif (is_string($value) && ! str_ends_with($lowerKey, 'url') && ! ($imageUrls && is_int($key))) {
                foreach (self::FORBIDDEN_WORDS as $word) {
                    if (str_contains(strtolower($value), $word)) {
                        $found[] = "value {$here} contains {$word}";
                    }
                }
            }
        }

        return $found;
    }
}
