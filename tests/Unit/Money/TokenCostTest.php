<?php

declare(strict_types=1);

use App\Support\Money\TokenCost;
use Psr\Log\LogLevel;
use Tests\Support\RecordingLogger;

beforeEach(function (): void {
    $this->logger = new RecordingLogger;
    $this->tokenCost = new TokenCost($this->logger);
});

it('computes ceil(Σ units × rate / 1M) exactly for current rates without logging', function (): void {
    // Pixelforge docs worked example: 115 / 1,530 / 6,450 / 8,000 tokens.
    $cost = $this->tokenCost->compute(
        ['input_text' => 115, 'input_image' => 1_530, 'output' => 6_450, 'reasoning' => 8_000],
        ['input_text' => 5_000_000_000, 'input_image' => 10_000_000_000, 'output' => 40_000_000_000, 'reasoning' => 12_500_000_000],
    );

    expect($cost->toInt())->toBe(373_875_000)
        ->and($this->logger->records)->toBe([]);
});

it('computes the demo Anthropic suggestion cost: 1,500 in / 200 out on Haiku 5.5', function (): void {
    $cost = $this->tokenCost->compute(
        ['input_text' => 1_500, 'output' => 200],
        ['input_text' => 100_000_000, 'output' => 500_000_000],
    );

    expect($cost->toInt())->toBe(250_000);
});

it('rounds a non-exact cost up by less than one nanodollar and logs a warning', function (): void {
    // 280 × 1,000,001 = 280,000,280 ⇒ 280.00028 nanodollars ⇒ 281.
    $cost = $this->tokenCost->compute(['output' => 280], ['output' => 1_000_001]);

    expect($cost->toInt())->toBe(281)
        ->and($this->logger->records)->toHaveCount(1)
        ->and($this->logger->records[0]['level'])->toBe(LogLevel::WARNING)
        ->and($this->logger->records[0]['context']['remainder'])->toBe(280);
});

it('is zero for no units', function (): void {
    expect($this->tokenCost->compute([], [])->toInt())->toBe(0)
        ->and($this->tokenCost->compute(['output' => 0], ['output' => 40_000_000_000])->toInt())->toBe(0);
});

it('rejects a kind without a rate and negative counts or rates', function (array $units, array $rates): void {
    expect(fn () => $this->tokenCost->compute($units, $rates))->toThrow(InvalidArgumentException::class);
})->with([
    'missing rate' => [['output' => 1], ['input_text' => 1]],
    'negative count' => [['output' => -1], ['output' => 1]],
    'negative rate' => [['output' => 1], ['output' => -1]],
]);

it('refuses to overflow instead of silently turning into a float', function (): void {
    expect(fn () => $this->tokenCost->compute(['output' => 2], ['output' => intdiv(PHP_INT_MAX, 2)]))
        ->toThrow(OverflowException::class)
        ->and(fn () => $this->tokenCost->compute(['a' => 1, 'b' => 1], ['a' => PHP_INT_MAX - 1_000_000, 'b' => 2]))
        ->toThrow(OverflowException::class);
});

it('contains no float or built-in rounding helpers', function (): void {
    $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 3).'/app/Support/Money/TokenCost.php'));
    $code = implode('', array_map(
        fn (array|string $token): string => is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
            : $token,
        $tokens,
    ));

    foreach (['float', 'round(', 'floatval', 'number_format(', 'floor(', 'ceil(', 'bcmath', '**'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("TokenCost must not use [{$forbidden}].");
    }
});
