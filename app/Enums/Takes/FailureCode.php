<?php

declare(strict_types=1);

namespace App\Enums\Takes;

/**
 * Why a take failed (D-049, D-044). Copy lives in 2-01 / lang files (D-057).
 *
 * Provider outcomes: generation_failed, internal_error.
 * Definite rejections: content_policy, daily_limit, validation, internal.
 * provider_busy: 30 min of 429/503, Pixelforge provably never created it.
 * submit_timeout: 30 min of ambiguous outcomes, a later success is an orphan.
 * cancelled: the customer cancelled while waiting for capacity.
 */
enum FailureCode: string
{
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';
    case ContentPolicy = 'content_policy';
    case DailyLimit = 'daily_limit';
    case Validation = 'validation';
    case Internal = 'internal';
    case ProviderBusy = 'provider_busy';
    case SubmitTimeout = 'submit_timeout';
    case Cancelled = 'cancelled';
}
