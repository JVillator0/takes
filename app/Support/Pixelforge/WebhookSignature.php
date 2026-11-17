<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use App\Enums\Webhooks\SignatureFailure;

/**
 * Pixelforge webhook signatures (W-02): the header is
 * `t=<unix>,v1=<hex>[,v1=<hex>…]` and each `v1` is the hex HMAC-SHA256 of
 * "<t>.<raw request body>" keyed with the API key's signing secret.
 *
 * Always the raw body, never a re-encoded one: JSON re-encoding changes
 * whitespace, key order and escaping, and with them the HMAC.
 */
final class WebhookSignature
{
    /**
     * Null when the signature is valid, otherwise why it is not.
     */
    public static function verify(
        ?string $header,
        string $rawBody,
        ?string $secret,
        int $toleranceSeconds,
        int $now,
    ): ?SignatureFailure {
        if ($secret === null || $secret === '') {
            return SignatureFailure::SecretMissing;
        }

        if ($header === null || trim($header) === '') {
            return SignatureFailure::HeaderMissing;
        }

        [$timestamp, $signatures] = self::parse($header);

        if ($timestamp === null) {
            return SignatureFailure::InvalidTimestamp;
        }

        if ($signatures === []) {
            return SignatureFailure::SignatureMissing;
        }

        if (abs($now - $timestamp) > $toleranceSeconds) {
            return SignatureFailure::Stale;
        }

        $expected = self::compute($timestamp, $rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return null;
            }
        }

        return SignatureFailure::Mismatch;
    }

    /** The hex HMAC-SHA256 Pixelforge sends as `v1`. */
    public static function compute(int $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    /**
     * The timestamp (null unless a positive integer) and every non-empty `v1`.
     *
     * @return array{0: int|null, 1: list<string>}
     */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $value = trim($value);

            if ($key === 't') {
                $timestamp = preg_match('/^[0-9]{1,12}$/', $value) === 1 && (int) $value > 0 ? (int) $value : null;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
