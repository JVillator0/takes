<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * A kind of billable unit (token kind) across every provider (spec 3-03 §2, D-009).
 * Pixelforge uses all four; Anthropic uses input_text and output only.
 * Each kind has an integer token column on usage_records (D-055).
 */
enum UsageKind: string
{
    case InputText = 'input_text';
    case InputImage = 'input_image';
    case Output = 'output';
    case Reasoning = 'reasoning';

    /** The usage_records column holding this kind's token count at settle. */
    public function tokenColumn(): string
    {
        return $this->value.'_tokens';
    }
}
