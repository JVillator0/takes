<?php

declare(strict_types=1);

use App\Support\Pixelforge\RetryAfter;
use Carbon\CarbonImmutable;

it('parses Retry-After as delay seconds or as an HTTP-date', function (?string $header, ?int $expected): void {
    $now = CarbonImmutable::parse('2026-11-17T17:21:31Z');

    expect(RetryAfter::parse($header, $now))->toBe($expected);
})->with([
    'seconds' => ['5', 5],
    'zero seconds' => ['0', 0],
    'padded seconds' => [' 12 ', 12],
    'HTTP-date in the future' => ['Tue, 17 Nov 2026 17:22:01 GMT', 30],
    'HTTP-date in the past' => ['Tue, 17 Nov 2026 17:20:00 GMT', 0],
    'missing' => [null, null],
    'empty' => ['', null],
    'negative' => ['-5', null],
    'fraction' => ['1.5', null],
    'garbage' => ['soon', null],
]);
