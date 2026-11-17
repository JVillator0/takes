<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Billing\PurchaseCreditsAction;
use App\Actions\Billing\RecordLedgerEntryAction;
use App\Actions\Billing\SettleUsageAction;
use App\Actions\Billing\VoidUsageAction;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ana López from the demo dataset (D-037, 2-product-design/demo-dataset.md),
 * built through the real money actions at the dataset's own times. At
 * 2026-11-17 12:02 UTC: balance $10.64754375, on hold $0.3139625, available
 * $10.33358125; November spend images $0.35214375, AI $0.0003125.
 */
final class BillingDemo
{
    public const string MOMENT = '2026-11-17T12:02:00Z';

    /**
     * @return array{customer: Customer, project: Project, takes: array<int, Take>, suggestion: Suggestion}
     */
    public static function ana(): array
    {
        CarbonImmutable::setTestNow('2026-11-16T18:00:00Z');
        $customer = Customer::factory()->withRole()->create(['name' => 'Ana López', 'email' => 'ana@example.com']);
        $project = Project::factory()->create(['customer_id' => $customer->id, 'name' => 'Lighthouse series']);
        $takes = [];

        self::at('2026-11-16T18:20:00Z', fn () => DB::transaction(fn () => app(RecordLedgerEntryAction::class)->execute(
            $customer,
            LedgerEntryType::PromoGrant,
            Nanodollars::fromInt(1_000_000_000),
            reason: 'welcome',
        )));

        $takes[6] = self::settledTake($customer, $project, '2026-11-16T18:31:00Z', 'Portrait crop, fog', [5, 0, 1650, 1200]);
        $takes[7] = self::settledTake($customer, $project, '2026-11-16T18:40:00Z', 'Black and white version', [6, 0, 1100, 900]);
        $takes[8] = self::settledTake($customer, $project, '2026-11-16T18:52:00Z', 'Warmer light, low sun', [6, 765, 1650, 1000]);

        DB::table('invoice_counters')->insert(['year' => 2026, 'last_number' => 3]);
        self::at('2026-11-17T10:02:00Z', fn () => app(PurchaseCreditsAction::class)->execute($customer, 10, (string) Str::uuid()));

        $takes[9] = self::take($customer, $project, '2026-11-17T11:40:00Z', 'Lighthouse [fail]');
        $failed = self::open($takes[9], Billing::DEFAULT_MAX_COST);
        self::at('2026-11-17T11:41:00Z', fn () => DB::transaction(fn () => app(VoidUsageAction::class)->execute($failed)));

        $suggestion = self::at('2026-11-17T11:58:00Z', fn (): Suggestion => Suggestion::factory()->create([
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'base_take_id' => $takes[8]->id,
            'instruction' => 'Make it portrait with warmer light',
        ]));
        $ai = self::at('2026-11-17T11:58:00Z', fn (): UsageRecord => DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
            $suggestion,
            Provider::Anthropic,
            Billing::estimate(250_000),
            Billing::anthropicRates(),
            'claude-haiku-5-5',
        )));
        self::at('2026-11-17T11:58:00Z', fn () => DB::transaction(fn () => app(SettleUsageAction::class)->execute(
            $ai,
            new UsageUnitsDTO(['input_text' => 1_500, 'output' => 200]),
            Billing::anthropicRates(),
            CarbonImmutable::now(),
        )));

        $takes[10] = self::settledTake($customer, $project, '2026-11-17T12:01:00Z', 'Lighthouse at golden hour, portrait, warmer light on the tower', [16, 765, 1100, 600]);

        // In progress at 12:02: the three holds (max cost × 1.25).
        $takes[11] = self::take($customer, $project, '2026-11-17T12:01:00Z', 'Night version with stars');
        self::open($takes[11], 69_035_000);
        $takes[12] = self::take($customer, $project, '2026-11-17T12:01:00Z', 'Portrait crop of the night version, fog');
        self::open($takes[12], 91_060_000);
        $takes[13] = self::take($customer, $project, '2026-11-17T12:02:00Z', 'Wide landscape of the lighthouse in a winter storm');
        self::open($takes[13], 91_075_000);

        CarbonImmutable::setTestNow(self::MOMENT);

        return ['customer' => $customer->refresh(), 'project' => $project, 'takes' => $takes, 'suggestion' => $suggestion];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function at(string $moment, callable $callback): mixed
    {
        CarbonImmutable::setTestNow($moment);

        return $callback();
    }

    public static function take(Customer $customer, Project $project, string $moment, string $prompt): Take
    {
        return self::at($moment, fn (): Take => Take::factory()->create([
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'prompt' => $prompt,
        ]));
    }

    public static function open(Take $take, int $maxCost): UsageRecord
    {
        return DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
            $take,
            Provider::Pixelforge,
            Billing::estimate($maxCost),
            Billing::pixelforgeRates(),
        ));
    }

    /**
     * @param  array{int, int, int, int}  $tokens  input text, input image, output, reasoning
     */
    public static function settledTake(Customer $customer, Project $project, string $moment, string $prompt, array $tokens): Take
    {
        $take = self::take($customer, $project, $moment, $prompt);
        $usage = self::open($take, Billing::DEFAULT_MAX_COST);

        self::settle($usage, $tokens);

        return $take;
    }

    /**
     * @param  array{int, int, int, int}  $tokens
     */
    public static function settle(UsageRecord $usage, array $tokens, ?RatesSnapshotDTO $rates = null): UsageRecord
    {
        [$text, $image, $output, $reasoning] = $tokens;

        return DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            new UsageUnitsDTO(['input_text' => $text, 'input_image' => $image, 'output' => $output, 'reasoning' => $reasoning]),
            $rates ?? Billing::pixelforgeRates(),
            CarbonImmutable::now(),
        ));
    }
}
