<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Ai\Agents\TakeChangeAgent;
use App\DTOs\Billing\EstimateDTO;
use App\Support\Money\TokenCost;

/**
 * The "Describe a change" hold, computed per request (spec 8 "Money", D-055):
 *
 *     input_bound  = ceil(utf8_bytes(request text) / 2) + INPUT_OVERHEAD_TOKENS
 *     output_bound = OUTPUT_BOUND (the agent's MaxTokens)
 *     max_cost     = ceil((input_bound × input rate + output_bound × output rate) / 1M)
 *     hold         = max_cost × 1.25 (EstimateDTO::fromCosts)
 *
 * The request text is everything the model reads: instructions, output
 * schema and the user message (base prompt + instruction). Two bytes per
 * token is far below the usual ~4, and the overhead covers what the API adds
 * around structured output and the message framing (the spike measured 959
 * input tokens for 2,010 bytes, D-076). If the actual still exceeds the hold,
 * the actual is charged and flagged (SettleUsageAction, money rule M-15).
 *
 * Known limit: two bytes per token is a typical-text bound, not a worst case.
 * A tokenizer that falls back to one token per byte (rare scripts, random
 * symbols) can exceed it; M-15 then charges the actual and flags
 * `over_estimate`, so money stays correct while the hold was too small.
 *
 * The minimum is a display floor: about four bytes per token, no output.
 */
final readonly class SuggestionEstimator
{
    public const int BYTES_PER_TOKEN_BOUND = 2;

    public const int BYTES_PER_TOKEN_TYPICAL = 4;

    public const int INPUT_OVERHEAD_TOKENS = 500;

    public const int OUTPUT_BOUND = TakeChangeAgent::MAX_OUTPUT_TOKENS;

    /**
     * Worst case bytes one instruction character adds to the JSON message: a
     * control character is escaped as `\u00XX`. Used for the quote shown
     * before the customer types (always ≥ the hold of the real request).
     */
    public const int MAX_BYTES_PER_INSTRUCTION_CHARACTER = 6;

    public function __construct(private TokenCost $tokenCost) {}

    /**
     * @param  array<string, int>  $rates  nanodollars per 1M tokens: `input_text` and `output`
     */
    public function estimate(string $requestText, array $rates): EstimateDTO
    {
        return $this->estimateForBytes(strlen($requestText), $rates);
    }

    /**
     * @param  array<string, int>  $rates  nanodollars per 1M tokens: `input_text` and `output`
     */
    public function estimateForBytes(int $bytes, array $rates): EstimateDTO
    {
        $maxInput = intdiv($bytes + self::BYTES_PER_TOKEN_BOUND - 1, self::BYTES_PER_TOKEN_BOUND) + self::INPUT_OVERHEAD_TOKENS;
        $minInput = intdiv($bytes, self::BYTES_PER_TOKEN_TYPICAL);

        return EstimateDTO::fromCosts(
            $this->tokenCost->compute(['input_text' => $minInput, 'output' => 0], $rates),
            $this->tokenCost->compute(['input_text' => $maxInput, 'output' => self::OUTPUT_BOUND], $rates),
        );
    }
}
