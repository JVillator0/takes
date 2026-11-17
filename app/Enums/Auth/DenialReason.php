<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * Reason codes returned by policies and throttles (D-050). Customer copy lives in lang/en/denials.php (D-057).
 */
enum DenialReason: string
{
    case InsufficientCredits = 'insufficient_credits';
    case AccountRestricted = 'account_restricted';
    case DailyTakeCap = 'daily_take_cap';
    case DemoQuotaReached = 'demo_quota_reached';
    case RateLimited = 'rate_limited';
    case AiPaused = 'ai_paused';
    case AiDailyLimit = 'ai_daily_limit';
}
