<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Billing\Provider;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfigured;
use App\Queries\Billing\GetAbsorbedDailyCostsQuery;
use App\Queries\Billing\GetPixelforgeDailyCostsQuery;
use App\Queries\Billing\GetSettledDailyCostsQuery;
use App\Queries\Billing\ListLedgerInvariantViolationsQuery;
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
 * take or suggestion, settled amounts consistent. `--usage` also compares our
 * per-day Pixelforge cost (settled plus the cost absorbed by orphan
 * successes, D-025) with Pixelforge's own report. Exit code 1 on any violation.
 */
#[Signature('billing:verify
    {--usage : Also compare per-day Pixelforge cost with GET /v1/usage}
    {--from= : First UTC day for --usage (Y-m-d, default: 7 days before --to)}
    {--to= : Last UTC day for --usage (Y-m-d, default: yesterday; today is still in flight)}')]
#[Description('Verify the ledger, balance and hold invariants')]
final class VerifyBillingCommand extends Command
{
    public function handle(
        ListLedgerInvariantViolationsQuery $invariantViolations,
        GetSettledDailyCostsQuery $settledCostPerDay,
        GetAbsorbedDailyCostsQuery $absorbedCostPerDay,
        GetPixelforgeDailyCostsQuery $pixelforgeDailyCosts,
    ): int {
        $range = null;

        // A range only scopes --usage: alone it would check nothing it names
        // and still print a pass, so refuse it instead of ignoring it. Presence,
        // not value: a bare `--from` (no value) leaves the option null.
        if (! $this->option('usage') && $this->input->hasParameterOption(['--from', '--to'], true)) {
            $this->error('--from and --to only apply to --usage; add --usage to compare that range.');

            return self::FAILURE;
        }

        if ($this->option('usage')) {
            // Bad input must never turn into a vacuous pass: refuse it up front.
            $range = $this->usageRange();

            if ($range === null) {
                return self::FAILURE;
            }
        }

        $violations = $invariantViolations->execute();

        if ($range !== null) {
            $violations = [...$violations, ...$this->usageViolations($settledCostPerDay, $absorbedCostPerDay, $pixelforgeDailyCosts, ...$range)];
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

    /**
     * Per day, Pixelforge's cost must equal ours: settled cost plus the cost
     * absorbed by orphan successes (issue #102). A matching day with absorbed
     * cost is printed as its own "Orphan success (cost absorbed)" line (D-058),
     * never as a mismatch.
     *
     * @return list<string>
     */
    private function usageViolations(
        GetSettledDailyCostsQuery $settledCostPerDay,
        GetAbsorbedDailyCostsQuery $absorbedCostPerDay,
        GetPixelforgeDailyCostsQuery $pixelforgeDailyCosts,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        try {
            $theirs = $pixelforgeDailyCosts->execute($from, $to);
        } catch (PixelforgeException|PixelforgeNotConfigured $e) {
            // Asked for a comparison we cannot make (no key, API down):
            // never report it as passed.
            return ['--usage: Pixelforge usage is unavailable ('.class_basename($e).': '.$e->getMessage().')'];
        }

        $settled = $settledCostPerDay->execute(Provider::Pixelforge, $from, $to);
        $absorbed = $absorbedCostPerDay->execute(Provider::Pixelforge, $from, $to);
        $violations = [];

        foreach (CarbonPeriodImmutable::create($from, $to) as $day) {
            $date = $day->toDateString();
            $settledCost = $settled[$date] ?? Nanodollars::zero();
            $absorbedCost = $absorbed[$date] ?? Nanodollars::zero();
            $theirCost = $theirs[$date] ?? Nanodollars::zero();

            if (! $settledCost->plus($absorbedCost)->equals($theirCost)) {
                $violations[] = $absorbedCost->isZero()
                    ? "usage {$date}: ledger cost {$settledCost->formatExact()} but Pixelforge reports {$theirCost->formatExact()}"
                    : "usage {$date}: ledger cost {$settledCost->formatExact()} + absorbed orphan cost {$absorbedCost->formatExact()} but Pixelforge reports {$theirCost->formatExact()}";
            } elseif (! $absorbedCost->isZero()) {
                $this->warn("usage {$date}: Orphan success (cost absorbed) {$absorbedCost->formatExact()}");
            }
        }

        if ($violations === []) {
            $this->info("Pixelforge usage matches from {$from->toDateString()} to {$to->toDateString()}.");
        }

        return $violations;
    }

    /**
     * Null when the option is absent; a strict Y-m-d date otherwise
     * (2026-02-30 does not roll over into March). An option given without a
     * value (`--from`) is refused, never read as "use the default".
     *
     * @throws InvalidArgumentException
     */
    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if ($value === null) {
            if ($this->input->hasParameterOption('--'.$name, true)) {
                throw new InvalidArgumentException('--'.$name.' needs a Y-m-d date, got none.');
            }

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
