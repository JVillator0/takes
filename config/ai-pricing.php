<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI provider rates (spec 3-03 §2, spec 8)
|--------------------------------------------------------------------------
|
| USD per 1M tokens as decimal STRINGS, keyed by provider, model and token
| kind. Read only through App\Support\Pricing\AiPricing, which parses them
| with Nanodollars::perMillionFromDecimalString. No floats in this file: a
| test scans it. Pixelforge rates are not here: they come from the
| generation's `rates` and GET /v1/pricing (spec 3-04).
|
| Source: https://platform.claude.com/docs/en/about-claude/pricing
| (Model pricing table), retrieved 2026-11-17.
|
| Claude Haiku 5.5 is priced by prompt length: these are the rates for
| prompts up to 100,000 tokens. A prompt over 100,000 tokens is billed at
| $0.50 / $2.50 per 1M instead; "Describe a change" prompts stay far below
| that, and spec 8 must keep it so.
|
*/

return [

    'anthropic' => [
        'claude-haiku-5-5' => [
            'input_text' => '0.10',
            'output' => '0.50',
        ],
    ],

];
