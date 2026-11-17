<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Billing\PixelforgeUsageSource;
use App\Enums\Billing\Provider;
use App\Exceptions\Billing\UsageSourceUnavailableException;
use App\Queries\Billing\LedgerInvariantViolationsQuery;
use App\Queries\Billing\SettledProviderCostPerDayQuery;
use App\Support\Money\Nanodollars;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriodImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Checks the money invariants (spec 3-03, D-059): Σ ledger = balance,
 * Σ open holds = held, one charge per usage, no open usage on a terminal
 * take, settled amounts consistent. `--usage` also compares our per-day
 * Pixelforge cost with Pixelforge's own report. Exit code 1 on any violation.
 */
#[Signature('billing:verify
    {--usage : Also compare per-day Pixelforge cost with GET /v1/usage}
    {--from= : First UTC day for --usage (Y-m-d, default: 7 days before --to)}
    {--to= : Last UTC day for --usage (Y-m-d, default: yesterday; today is still in flight)}')]
#[Description('Verify the ledger, balance and hold invariants')]
final class VerifyBillingCommand extends Command
{
    public function handle(
        LedgerInvariantViolationsQuery $invariantViolations,
        SettledProviderCostPerDayQuery $settledCostPerDay,
        PixelforgeUsageSource $usageSource,
    ): int {
        $range = null;

        if ($this->option('usage')) {
            // Bad input must never turn into a vacuous pass: refuse it up front.
            $range = $this->usageRange();

            if ($range === null) {
                return self::FAILURE;
            }
        }

        $violations = $invariantViolations->execute();

        if ($range !== null) {
            $violations = [...$violations, ...$this->usageViolations($settledCostPerDay, $usageSource, ...$range)];
        }

        if ($violations === []) {
            $this->info('Billing invariants hold.');

            return self::SUCCESS;
        }

        foreach ($violations as $violation) {
            $this->error($violation);
        }

        $this->error(count($violations).' billing invariant violation(s).');

        return self::FAILURE;
    }

    /**
     * The inclusive UTC day range for --usage, or null (after printing why)
     * when --from/--to is not a real Y-m-d date or the range is inverted.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function usageRange(): ?array
    {
        try {
            $to = $this->dateOption('to') ?? CarbonImmutable::now('UTC')->subDay()->startOfDay();
            $from = $this->dateOption('from') ?? $to->subDays(6);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        if ($from->greaterThan($to)) {
            $this->error("--from {$from->toDateString()} is after --to {$to->toDateString()}.");

            return null;
        }

        return [$from, $to];
    }

    /** @return list<string> */
    private function usageViolations(
        SettledProviderCostPerDayQuery $settledCostPerDay,
        PixelforgeUsageSource $usageSource,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        try {
            $theirs = $usageSource->dailyCosts($from, $to);
        } catch (UsageSourceUnavailableException $e) {
            // Asked for a comparison we cannot make: never report it as passed.
            return ["--usage: {$e->getMessage()}"];
        }

        $ours = $settledCostPerDay->execute(Provider::Pixelforge, $from, $to);
        $violations = [];

        foreach (CarbonPeriodImmutable::create($from, $to) as $day) {
            $date = $day->toDateString();
            $ourCost = $ours[$date] ?? Nanodollars::zero();
            $theirCost = $theirs[$date] ?? Nanodollars::zero();

            // Orphan-success days (D-025) differ until their absorbed cost is
            // recorded and attributed here (issue #102).
            if (! $ourCost->equals($theirCost)) {
                $violations[] = "usage {$date}: ledger cost {$ourCost->formatExact()} but Pixelforge reports {$theirCost->formatExact()}";
            }
        }

        if ($violations === []) {
            $this->info("Pixelforge usage matches from {$from->toDateString()} to {$to->toDateString()}.");
        }

        return $violations;
    }

    /**
     * Null when the option is absent; a strict Y-m-d date otherwise
     * (2026-02-30 does not roll over into March).
     *
     * @throws InvalidArgumentException
     */
    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')
            : null;

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('--'.$name.' must be a real date in Y-m-d format, got ['.(is_string($value) ? $value : '').'].');
        }

        return $date;
    }
}
