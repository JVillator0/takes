<?php

declare(strict_types=1);

use App\Actions\Billing\OpenUsageAction;
use App\Actions\Billing\SettleUsageAction;
use App\Actions\Billing\VoidUsageAction;
use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Billing\UsageAlreadyClosedException;
use App\Models\LedgerEntry;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Billing;

/*
 * Open / Settle / Void (spec 3-03 §6). Amounts are integer nanodollars.
 * Fixture: $10.00 balance; the example usage costs 268,375,000 and charges
 * 335,468,750; the default hold is 467,343,750.
 */

beforeEach(function (): void {
    $this->customer = Billing::fundedCustomer(10_000_000_000);
});

describe('open', function (): void {
    it('places the max charge on hold without touching the balance or the ledger', function (): void {
        $usage = Billing::openForNewTake($this->customer);
        $this->customer->refresh();

        expect($usage->status)->toBe(UsageStatus::Open)
            ->and($usage->provider)->toBe(Provider::Pixelforge)
            ->and($usage->billable_type)->toBe('take')
            ->and($usage->min_cost_nanos)->toBe(0)
            ->and($usage->max_cost_nanos)->toBe(Billing::DEFAULT_MAX_COST)
            ->and($usage->max_charge_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE)
            ->and($usage->markup_bps)->toBe(2500)
            ->and($usage->rates)->toBe(Billing::pixelforgeRates()->toArray())
            ->and($usage->provider_cost_nanos)->toBeNull()
            ->and($usage->charge_nanos)->toBeNull()
            ->and($this->customer->balance_nanos)->toBe(10_000_000_000)
            ->and($this->customer->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE)
            ->and($this->customer->available()->toInt())->toBe(10_000_000_000 - Billing::DEFAULT_MAX_CHARGE)
            ->and(LedgerEntry::query()->where('type', LedgerEntryType::Charge)->count())->toBe(0);

        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('opens when available exactly covers the hold', function (): void {
        $customer = Billing::fundedCustomer(Billing::DEFAULT_MAX_CHARGE);

        Billing::openForNewTake($customer);

        expect($customer->refresh()->available()->toInt())->toBe(0);
    });

    it('throws InsufficientCreditsException one nanodollar short and changes nothing', function (): void {
        $customer = Billing::fundedCustomer(Billing::DEFAULT_MAX_CHARGE - 1);

        expect(fn () => Billing::openForNewTake($customer))->toThrow(InsufficientCreditsException::class);

        expect(UsageRecord::query()->where('customer_id', $customer->id)->count())->toBe(0)
            ->and($customer->refresh()->held_nanos)->toBe(0);
    });

    it('counts existing holds against available', function (): void {
        $customer = Billing::fundedCustomer(Billing::DEFAULT_MAX_CHARGE * 2 - 1);

        Billing::openForNewTake($customer);

        expect(fn () => Billing::openForNewTake($customer))->toThrow(InsufficientCreditsException::class);
        expect($customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE);
    });

    it('returns the existing usage when the same billable is opened again, with no second hold', function (): void {
        $take = Take::factory()->create(['customer_id' => $this->customer->id]);
        $open = fn (): UsageRecord => DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)
            ->execute($take, Provider::Pixelforge, Billing::estimate(), Billing::pixelforgeRates()));

        $first = $open();
        $second = $open();

        expect($second->id)->toBe($first->id)
            ->and($this->customer->refresh()->held_nanos)->toBe(Billing::DEFAULT_MAX_CHARGE);
    });

    it('refuses to reopen a billable whose usage is already settled or voided', function (string $close): void {
        $usage = Billing::openForNewTake($this->customer);
        $close === 'settle'
            ? Billing::settle($usage)
            : DB::transaction(fn () => app(VoidUsageAction::class)->execute($usage));
        $before = $this->customer->refresh()->only(['balance_nanos', 'held_nanos']);
        $take = Take::query()->findOrFail($usage->billable_id);

        expect(fn () => DB::transaction(fn () => app(OpenUsageAction::class)
            ->execute($take, Provider::Pixelforge, Billing::estimate(), Billing::pixelforgeRates())))
            ->toThrow(UsageAlreadyClosedException::class, "Usage {$usage->id} is already");

        expect($this->customer->refresh()->only(['balance_nanos', 'held_nanos']))->toBe($before)
            ->and(UsageRecord::query()->count())->toBe(1);
    })->with(['settle', 'void']);

    it('refuses an estimate whose charges use another markup, before any hold', function (EstimateDTO $estimate): void {
        $take = Take::factory()->create(['customer_id' => $this->customer->id]);

        expect(fn () => DB::transaction(fn () => app(OpenUsageAction::class)
            ->execute($take, Provider::Pixelforge, $estimate, Billing::pixelforgeRates())))
            ->toThrow(InvalidArgumentException::class, 'with the 2500 bps markup');

        expect(UsageRecord::query()->count())->toBe(0)
            ->and($this->customer->refresh()->held_nanos)->toBe(0);
    })->with([
        'no markup on the hold' => fn () => new EstimateDTO(Billing::nanos(0), Billing::nanos(Billing::DEFAULT_MAX_COST), Billing::nanos(0), Billing::nanos(Billing::DEFAULT_MAX_COST)),
        'hold one nanodollar short' => fn () => new EstimateDTO(Billing::nanos(0), Billing::nanos(Billing::DEFAULT_MAX_COST), Billing::nanos(0), Billing::nanos(Billing::DEFAULT_MAX_CHARGE - 1)),
        'minimum charge without markup' => fn () => new EstimateDTO(Billing::nanos(100_000_000), Billing::nanos(Billing::DEFAULT_MAX_COST), Billing::nanos(100_000_000), Billing::nanos(Billing::DEFAULT_MAX_CHARGE)),
    ]);

    it('opens an Anthropic usage for a suggestion through the same ledger', function (): void {
        $suggestion = Suggestion::factory()->create(['customer_id' => $this->customer->id]);

        $usage = DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
            $suggestion,
            Provider::Anthropic,
            Billing::estimate(400_000, 50_000),
            Billing::anthropicRates(),
            'claude-haiku-5-5',
        ));

        expect($usage->billable_type)->toBe('suggestion')
            ->and($usage->provider)->toBe(Provider::Anthropic)
            ->and($usage->model)->toBe('claude-haiku-5-5')
            ->and($usage->min_charge_nanos)->toBe(62_500)
            ->and($usage->max_charge_nanos)->toBe(500_000);

        // 1,000 input × 100 + 600 output × 500 = 400,000 cost ⇒ 500,000 charge.
        $settled = DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            new UsageUnitsDTO(['input_text' => 1_000, 'output' => 600]),
            Billing::anthropicRates(),
            CarbonImmutable::parse('2026-11-17T10:00:00Z'),
            'msg_01',
        ));

        expect($settled->provider_cost_nanos)->toBe(400_000)
            ->and($settled->charge_nanos)->toBe(500_000)
            ->and($settled->input_text_tokens)->toBe(1_000)
            ->and($settled->output_tokens)->toBe(600)
            ->and($settled->input_image_tokens)->toBeNull()
            ->and($settled->reasoning_tokens)->toBeNull()
            ->and($this->customer->refresh()->balance_nanos)->toBe(10_000_000_000 - 500_000);

        Billing::assertCachedColumnsMatch($this->customer);
    });
});

describe('settle', function (): void {
    it('charges cost + 25% once, releases the hold and records the settlement', function (): void {
        $usage = Billing::openForNewTake($this->customer);

        $settled = DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            Billing::exampleUnits(),
            Billing::pixelforgeRates(),
            CarbonImmutable::parse('2026-11-17T23:30:00-05:00'),
            'gen_abc',
        ));
        $this->customer->refresh();

        expect($settled->status)->toBe(UsageStatus::Settled)
            ->and($settled->provider_cost_nanos)->toBe(Billing::EXAMPLE_COST)
            ->and($settled->charge_nanos)->toBe(Billing::EXAMPLE_CHARGE)
            ->and($settled->units)->toBe(Billing::exampleUnits()->tokens)
            ->and($settled->input_text_tokens)->toBe(115)
            ->and($settled->input_image_tokens)->toBe(1_530)
            ->and($settled->output_tokens)->toBe(6_000)
            ->and($settled->reasoning_tokens)->toBe(1_000)
            ->and($settled->external_id)->toBe('gen_abc')
            ->and($settled->flags)->toBe([])
            // billing_date is the UTC date of completion: 23:30 EST is the 18th in UTC.
            ->and($settled->billing_date?->toDateString())->toBe('2026-11-18')
            ->and($settled->refresh()->completed_at?->toIso8601String())->toBe('2026-11-18T04:30:00+00:00')
            ->and($this->customer->balance_nanos)->toBe(10_000_000_000 - Billing::EXAMPLE_CHARGE)
            ->and($this->customer->held_nanos)->toBe(0);

        $charge = LedgerEntry::query()->where('usage_record_id', $usage->id)->sole();

        expect($charge->type)->toBe(LedgerEntryType::Charge)
            ->and($charge->amount_nanos)->toBe(-Billing::EXAMPLE_CHARGE)
            ->and($charge->customer_id)->toBe($this->customer->id);

        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('overwrites the open rates snapshot with the rates the provider applied (D-055)', function (): void {
        $usage = Billing::openForNewTake($this->customer);
        $applied = new RatesSnapshotDTO([
            'input_text' => 4_000_000_000,
            'input_image' => 8_000_000_000,
            'output' => 32_000_000_000,
            'reasoning' => 10_000_000_000,
        ]);

        $settled = DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            Billing::exampleUnits(),
            $applied,
            CarbonImmutable::parse('2026-11-17T12:00:00Z'),
        ));

        // 115×4,000 + 1,530×8,000 + 6,000×32,000 + 1,000×10,000 = 214,700,000.
        expect($settled->rates)->toBe($applied->toArray())
            ->and($settled->provider_cost_nanos)->toBe(214_700_000)
            ->and($settled->charge_nanos)->toBe(268_375_000);
    });

    it('is a no-op when settled twice: one charge, one balance move', function (): void {
        $usage = Billing::openForNewTake($this->customer);

        Billing::settle($usage);
        $again = Billing::settle($usage, new UsageUnitsDTO(['output' => 9_999]));

        expect($again->charge_nanos)->toBe(Billing::EXAMPLE_CHARGE)
            ->and(LedgerEntry::query()->where('usage_record_id', $usage->id)->count())->toBe(1)
            ->and($this->customer->refresh()->balance_nanos)->toBe(10_000_000_000 - Billing::EXAMPLE_CHARGE);

        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('is a no-op after a void: failed stays free', function (): void {
        $usage = Billing::openForNewTake($this->customer);

        DB::transaction(fn () => app(VoidUsageAction::class)->execute($usage));
        $settled = Billing::settle($usage);

        expect($settled->status)->toBe(UsageStatus::Voided)
            ->and($settled->charge_nanos)->toBe(0)
            ->and(LedgerEntry::query()->where('usage_record_id', $usage->id)->count())->toBe(0)
            ->and($this->customer->refresh()->balance_nanos)->toBe(10_000_000_000);

        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('settles when a kind with no rate is reported as 0 tokens', function (): void {
        $suggestion = Suggestion::factory()->create(['customer_id' => $this->customer->id]);
        $usage = DB::transaction(fn (): UsageRecord => app(OpenUsageAction::class)->execute(
            $suggestion,
            Provider::Anthropic,
            Billing::estimate(400_000),
            Billing::anthropicRates(),
        ));

        // Anthropic has no input_image or reasoning rate; a mapper may still report them as 0.
        $settled = DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            new UsageUnitsDTO(['input_text' => 10, 'input_image' => 0, 'reasoning' => 0]),
            Billing::anthropicRates(),
            CarbonImmutable::parse('2026-11-17T12:00:00Z'),
        ));

        expect($settled->status)->toBe(UsageStatus::Settled)
            ->and($settled->provider_cost_nanos)->toBe(1_000)
            ->and($settled->charge_nanos)->toBe(1_250)
            ->and($settled->input_image_tokens)->toBe(0)
            ->and($settled->reasoning_tokens)->toBe(0)
            ->and($this->customer->refresh()->held_nanos)->toBe(0);

        $this->artisan('billing:verify')->assertSuccessful();
    });

    it('settles a zero-token usage at zero with no ledger entry', function (): void {
        $usage = Billing::openForNewTake($this->customer);

        $settled = Billing::settle($usage, new UsageUnitsDTO([]));

        expect($settled->status)->toBe(UsageStatus::Settled)
            ->and($settled->provider_cost_nanos)->toBe(0)
            ->and($settled->charge_nanos)->toBe(0)
            ->and(LedgerEntry::query()->where('usage_record_id', $usage->id)->count())->toBe(0)
            ->and($this->customer->refresh()->held_nanos)->toBe(0);

        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('charges the actual amount above the hold, flags over_estimate and logs a warning', function (): void {
        Log::spy();
        $customer = Billing::fundedCustomer(300_000_000);
        // Hold 250,000,000 (cost 200,000,000) but the example charges 335,468,750.
        $usage = Billing::openForNewTake($customer, 200_000_000);

        $settled = Billing::settle($usage);
        $customer->refresh();

        expect($settled->charge_nanos)->toBe(Billing::EXAMPLE_CHARGE)
            ->and($settled->flags)->toBe(['over_estimate'])
            ->and($customer->balance_nanos)->toBe(300_000_000 - Billing::EXAMPLE_CHARGE)
            ->and($customer->held_nanos)->toBe(0)
            // available may go below zero by the difference (spec 3-03 §6).
            ->and($customer->available()->toInt())->toBe(-35_468_750);

        Log::shouldHaveReceived('warning')->once()->with('billing.over_estimate', Mockery::on(
            fn (array $context): bool => $context['usage_record_id'] === $usage->id
                && $context['charge_nanos'] === Billing::EXAMPLE_CHARGE
                && $context['max_charge_nanos'] === 250_000_000,
        ));
        Billing::assertCachedColumnsMatch($customer);
    });

    it('logs a warning when the ceil guard rounds the cost up', function (): void {
        Log::spy();
        $usage = Billing::openForNewTake($this->customer);

        // Synthetic odd rate: 1,000 nanodollars per 1M ⇒ 1 token = 0.001 nanodollars.
        $settled = DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            new UsageUnitsDTO(['output' => 1]),
            new RatesSnapshotDTO(['output' => 1_000]),
            CarbonImmutable::parse('2026-11-17T12:00:00Z'),
        ));

        expect($settled->provider_cost_nanos)->toBe(1)
            ->and($settled->charge_nanos)->toBe(2);

        Log::shouldHaveReceived('warning')->once()->with('Token cost is not a whole number of nanodollars; rounded up.', Mockery::on(
            fn (array $context): bool => $context['usage_record_id'] === $usage->id
                && $context['customer_id'] === $this->customer->id,
        ));
    });

    it('settles with units × rates and flags a disagreeing provider cost', function (): void {
        Log::spy();
        $usage = Billing::openForNewTake($this->customer);

        $settled = DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            Billing::exampleUnits(),
            Billing::pixelforgeRates(),
            CarbonImmutable::parse('2026-11-17T12:00:00Z'),
            reportedCost: Nanodollars::fromInt(Billing::EXAMPLE_COST + 1),
        ));

        expect($settled->provider_cost_nanos)->toBe(Billing::EXAMPLE_COST)
            ->and($settled->flags)->toBe(['cost_mismatch']);

        Log::shouldHaveReceived('warning')->once()->with('billing.cost_mismatch', Mockery::type('array'));
    });

    it('does not flag a provider cost that agrees', function (): void {
        $usage = Billing::openForNewTake($this->customer);

        $settled = DB::transaction(fn (): UsageRecord => app(SettleUsageAction::class)->execute(
            $usage,
            Billing::exampleUnits(),
            Billing::pixelforgeRates(),
            CarbonImmutable::parse('2026-11-17T12:00:00Z'),
            reportedCost: Nanodollars::fromInt(Billing::EXAMPLE_COST),
        ));

        expect($settled->flags)->toBe([]);
    });

    it('re-reads the usage under the lock instead of trusting a stale model', function (): void {
        $usage = Billing::openForNewTake($this->customer);
        $stale = UsageRecord::query()->findOrFail($usage->id);

        Billing::settle($usage);
        // $stale still says "open" in memory.
        Billing::settle($stale);

        expect(LedgerEntry::query()->where('usage_record_id', $usage->id)->count())->toBe(1);
        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('rolls the whole settlement back when the caller transaction fails', function (): void {
        $usage = Billing::openForNewTake($this->customer);

        try {
            DB::transaction(function () use ($usage): void {
                Billing::settle($usage);

                throw new RuntimeException('caller failed after settling');
            });
        } catch (RuntimeException) {
        }

        expect($usage->refresh()->status)->toBe(UsageStatus::Open)
            ->and(LedgerEntry::query()->where('usage_record_id', $usage->id)->count())->toBe(0);
        Billing::assertCachedColumnsMatch($this->customer);
    });
});

describe('void', function (): void {
    it('releases the hold and charges nothing', function (): void {
        $usage = Billing::openForNewTake($this->customer);

        $voided = DB::transaction(fn (): UsageRecord => app(VoidUsageAction::class)->execute($usage));
        $this->customer->refresh();

        expect($voided->status)->toBe(UsageStatus::Voided)
            ->and($voided->provider_cost_nanos)->toBe(0)
            ->and($voided->charge_nanos)->toBe(0)
            ->and($this->customer->held_nanos)->toBe(0)
            ->and($this->customer->balance_nanos)->toBe(10_000_000_000)
            ->and(LedgerEntry::query()->where('usage_record_id', $usage->id)->count())->toBe(0);

        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('is a no-op after a settle: it never refunds a charge', function (): void {
        $usage = Billing::openForNewTake($this->customer);
        Billing::settle($usage);

        $result = DB::transaction(fn (): UsageRecord => app(VoidUsageAction::class)->execute($usage));

        expect($result->status)->toBe(UsageStatus::Settled)
            ->and($result->charge_nanos)->toBe(Billing::EXAMPLE_CHARGE)
            ->and($this->customer->refresh()->balance_nanos)->toBe(10_000_000_000 - Billing::EXAMPLE_CHARGE);

        Billing::assertCachedColumnsMatch($this->customer);
    });

    it('is a no-op when voided twice', function (): void {
        $usage = Billing::openForNewTake($this->customer);
        $other = Billing::openForNewTake($this->customer);

        DB::transaction(fn () => app(VoidUsageAction::class)->execute($usage));
        DB::transaction(fn () => app(VoidUsageAction::class)->execute($usage));

        // The second void must not release the other usage's hold.
        expect($this->customer->refresh()->held_nanos)->toBe($other->max_charge_nanos);
        Billing::assertCachedColumnsMatch($this->customer);
    });
});
