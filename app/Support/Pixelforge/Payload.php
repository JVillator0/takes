<?php

declare(strict_types=1);

namespace App\Support\Pixelforge;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Strict readers for decoded Pixelforge JSON. Every DTO goes through these,
 * so a malformed payload fails with an InvalidArgumentException naming the
 * field (spec 5 records it as `invalid_snapshot`), never a TypeError.
 */
final class Payload
{
    /**
     * @param  array<mixed>  $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw self::invalid($key, 'a non-empty string');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw self::invalid($key, 'a string or null');
        }

        return $value;
    }

    /**
     * A token count or similar: an integer, never negative.
     *
     * @param  array<mixed>  $data
     */
    public static function count(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw self::invalid($key, 'a non-negative integer');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw self::invalid($key, 'a boolean');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw self::invalid($key, 'an object or array');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>|null
     */
    public static function nullableArray(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_array($value)) {
            throw self::invalid($key, 'an object or null');
        }

        return $value;
    }

    /**
     * A JSON object of string keys to string values (generation metadata).
     *
     * @param  array<mixed>  $data
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        $map = [];

        foreach (self::array($data, $key) as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw self::invalid($key, 'an object of string values');
            }

            $map[$name] = $value;
        }

        return $map;
    }

    /**
     * An ISO 8601 timestamp, normalized to UTC.
     *
     * @param  array<mixed>  $data
     */
    public static function timestamp(array $data, string $key): CarbonImmutable
    {
        return self::nullableTimestamp($data, $key) ?? throw self::invalid($key, 'an ISO 8601 timestamp');
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function nullableTimestamp(array $data, string $key): ?CarbonImmutable
    {
        $value = self::nullableString($data, $key);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw self::invalid($key, 'an ISO 8601 timestamp');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw self::invalid($key, 'an ISO 8601 timestamp');
        }
    }

    /**
     * A UTC calendar day, "YYYY-MM-DD", at midnight UTC.
     *
     * @param  array<mixed>  $data
     */
    public static function date(array $data, string $key): CarbonImmutable
    {
        $value = self::string($data, $key);
        try {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')
                : null;
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw self::invalid($key, 'a YYYY-MM-DD date');
        }

        return $date;
    }

    public static function invalid(string $key, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException("Invalid Pixelforge payload: [{$key}] must be {$expected}.");
    }
}
