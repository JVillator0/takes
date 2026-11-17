<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\Billing\Provider;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What Billing › Reports shows (A03, D-035): a UTC day range (inclusive), grouped by day or by customer, optionally
 * for one customer and one provider.
 */
final readonly class BillingReportFilterDTO
{
    public const string BY_DAY = 'day';

    public const string BY_CUSTOMER = 'customer';

    public const int MAX_DAYS = 366;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public string $groupBy = self::BY_DAY,
        public ?int $customerId = null,
        public ?Provider $provider = null,
    ) {
        if (! in_array($groupBy, [self::BY_DAY, self::BY_CUSTOMER], true)) {
            throw new InvalidArgumentException("Unknown grouping {$groupBy}.");
        }

        if ($until->lessThan($from) || $from->diffInDays($until) > self::MAX_DAYS) {
            throw new InvalidArgumentException('The report range must be ordered and at most a year.');
        }
    }

    /**
     * @param  array{from: string, until: string, group_by?: string, customer_id?: int|null, provider?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        $provider = $data['provider'] ?? null;

        return new self(
            CarbonImmutable::parse($data['from'], 'UTC')->startOfDay(),
            CarbonImmutable::parse($data['until'], 'UTC')->startOfDay(),
            $data['group_by'] ?? self::BY_DAY,
            $data['customer_id'] ?? null,
            $provider === null || $provider === '' ? null : Provider::from($provider),
        );
    }

    /** The default range: the last 30 UTC days, today included. */
    public static function lastThirtyDays(): self
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return new self($today->subDays(29), $today);
    }
}
