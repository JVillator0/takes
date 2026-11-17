<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;

/**
 * Pixelforge's own cost per UTC billing day (`GET /v1/usage`, spec 3-04):
 * the outside view `billing:verify --usage` compares the ledger with (D-059).
 */
final readonly class GetPixelforgeDailyCostsQuery
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array<string, Nanodollars> keyed by 'Y-m-d', inclusive range
     *
     * @throws PixelforgeNotConfigured when the API key is missing
     * @throws PixelforgeException when the API cannot answer
     */
    public function execute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $costs = [];

        foreach ($this->client->getUsage($from, $to)->days as $day) {
            $costs[$day->date->toDateString()] = $day->cost;
        }

        return $costs;
    }
}
