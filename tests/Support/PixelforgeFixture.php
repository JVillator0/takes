<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Pixelforge payloads for Http::fake() and DTO tests (tests/Fixtures/Pixelforge).
 *
 * Copied verbatim from https://pixelforge.promptedit.dev/docs: generation-queued
 * (create response), generation-succeeded, usage, error-validation-failed.
 * Captured from the real API on 2026-11-17: pricing (GET /v1/pricing) and
 * error-invalid-api-key (GET /v1/usage without a key).
 * Assembled from the docs: generation-failed (the documented generation
 * object with the documented failed `error` block) and the other error
 * bodies (documented shape, code, type and status; message from the table).
 */
final class PixelforgeFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        $path = __DIR__."/../Fixtures/Pixelforge/{$name}.json";
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Missing Pixelforge fixture [{$name}].");
        }

        /** @var array<string, mixed> */
        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A generation object with a few fields replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generation(string $status = 'queued', array $overrides = []): array
    {
        return array_replace(self::json("generation-{$status}"), $overrides);
    }

    /**
     * The documented error body for a code.
     *
     * @return array{error: array{type: string, code: string, message: string}}
     */
    public static function error(string $code): array
    {
        [$type, $message] = match ($code) {
            'invalid_api_key' => ['authentication_error', 'The API key is missing, malformed or revoked.'],
            'validation_failed' => ['invalid_request_error', 'A parameter is missing or invalid.'],
            'content_policy_violation' => ['invalid_request_error', 'The prompt was rejected. Nothing was created or billed.'],
            'not_found' => ['invalid_request_error', 'No such resource, or it belongs to another account.'],
            'idempotency_key_reused' => ['idempotency_error', 'The Idempotency-Key was already used with different parameters.'],
            'rate_limit_exceeded' => ['rate_limit_error', 'Too many requests this minute.'],
            'concurrency_limit_exceeded' => ['rate_limit_error', 'Too many generations queued or processing.'],
            'daily_limit_exceeded' => ['rate_limit_error', 'The daily generation quota (UTC) is used up.'],
            'overloaded' => ['api_error', 'Pixelforge is temporarily overloaded.'],
            'date_range_too_long' => ['invalid_request_error', 'The usage date range covers too many days.'],
            default => ['api_error', 'Unexpected error.'],
        };

        return ['error' => ['type' => $type, 'code' => $code, 'message' => $message]];
    }
}
