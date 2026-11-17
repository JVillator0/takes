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
        $violations = $invariantViolations->execute();

        if ($this->option('usage')) {
            $violations = [...$violations, ...$this->usageViolations($settledCostPerDay, $usageSource)];
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

    /** @return list<string> */
    private function usageViolations(SettledProviderCostPerDayQuery $settledCostPerDay, PixelforgeUsageSource $usageSource): array
    {
        $to = $this->dateOption('to') ?? CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $from = $this->dateOption('from') ?? $to->subDays(6);

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

            if (! $ourCost->equals($theirCost)) {
                $violations[] = "usage {$date}: ledger cost {$ourCost->formatExact()} but Pixelforge reports {$theirCost->formatExact()}";
            }
        }

        if ($violations === []) {
            $this->info("Pixelforge usage matches from {$from->toDateString()} to {$to->toDateString()}.");
        }

        return $violations;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        return is_string($value) && $value !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC') ?: null
            : null;
    }
}
