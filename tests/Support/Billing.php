<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Billing\RecordLedgerEntryAction;
use App\Actions\Billing\SettleUsageAction;
use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Models\Customer;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Money fixtures for billing tests. Every amount goes through the real
 * actions, so the cached columns always match the ledger.
 */
final class Billing
{
    /** The Pixelforge example usage: 268,375,000 cost, 335,468,750 charge. */
    public const int EXAMPLE_COST = 268_375_000;

    public const int EXAMPLE_CHARGE = 335_468_750;

    /** Default hold: the docs' worked example max cost 373,875,000 × 1.25. */
    public const int DEFAULT_MAX_COST = 373_875_000;

    public const int DEFAULT_MAX_CHARGE = 467_343_750;

    /** Pixelforge rates, nanodollars per 1M tokens. */
    public static function pixelforgeRates(): RatesSnapshotDTO
    {
        return new RatesSnapshotDTO([
            'input_text' => 5_000_000_000,
            'input_image' => 10_000_000_000,
            'output' => 40_000_000_000,
            'reasoning' => 12_500_000_000,
        ]);
    }

    /** Anthropic Haiku 5.5 rates, nanodollars per 1M tokens. */
    public static function anthropicRates(): RatesSnapshotDTO
    {
        return new RatesSnapshotDTO([
            'input_text' => 100_000_000,
            'output' => 500_000_000,
        ]);
    }

    /** 115×5,000 + 1,530×10,000 + 6,000×40,000 + 1,000×12,500 = 268,375,000. */
    public static function exampleUnits(): UsageUnitsDTO
    {
        return new UsageUnitsDTO([
            'input_text' => 115,
            'input_image' => 1_530,
            'output' => 6_000,
            'reasoning' => 1_000,
        ]);
    }

    public static function estimate(int $maxCost = self::DEFAULT_MAX_COST, int $minCost = 0): EstimateDTO
    {
        return EstimateDTO::fromCosts(Nanodollars::fromInt($minCost), Nanodollars::fromInt($maxCost));
    }

    public static function nanos(int $nanos): Nanodollars
    {
        return Nanodollars::fromInt($nanos);
    }

    public static function fund(Customer $customer, int $nanos): void
    {
        DB::transaction(fn () => app(RecordLedgerEntryAction::class)->execute(
            customer: $customer,
            type: LedgerEntryType::PromoGrant,
            amount: Nanodollars::fromInt($nanos),
            reason: 'test funding',
        ));
    }

    public static function fundedCustomer(int $nanos = 10_000_000_000): Customer
    {
        $customer = Customer::factory()->create();
        self::fund($customer, $nanos);

        return $customer->refresh();
    }

    public static function openForNewTake(Customer $customer, int $maxCost = self::DEFAULT_MAX_COST): UsageRecord
    {
        $take = Take::factory()->create(['customer_id' => $customer->id]);

        return DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
            $take,
            Provider::Pixelforge,
            self::estimate($maxCost),
            self::pixelforgeRates(),
        ));
    }

    public static function settle(UsageRecord $usage, ?UsageUnitsDTO $units = null): UsageRecord
    {
        return DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            $units ?? self::exampleUnits(),
            self::pixelforgeRates(),
            CarbonImmutable::parse('2026-11-17T12:00:00Z'),
        ));
    }

    /** Σ ledger and Σ open holds, straight from the source tables. */
    public static function assertCachedColumnsMatch(Customer $customer): void
    {
        $customer->refresh();
        $ledger = (int) DB::table('ledger_entries')->where('customer_id', $customer->id)->sum('amount_nanos');
        $held = (int) DB::table('usage_records')->where('customer_id', $customer->id)->where('status', 'open')->sum('max_charge_nanos');

        expect($customer->balance_nanos)->toBe($ledger)
            ->and($customer->held_nanos)->toBe($held);
    }
}
