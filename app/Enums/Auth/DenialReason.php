<?php

declare(strict_types=1);

namespace App\Enums\Auth;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;

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

    /** A shared, publicly known demo login cannot change its name, email or password (D-072). */
    case DemoAccountLocked = 'demo_account_locked';

    /**
     * A policy denial carrying this code: `Gate::inspect()->code()` is the enum value.
     */
    public function deny(): Response
    {
        return Response::deny($this->message(), $this->value);
    }

    /**
     * The 2-01 customer copy for this reason. Every case has a line in
     * lang/en/denials.php (tested); a missing line falls back to the code.
     */
    public function message(): string
    {
        $key = 'denials.'.$this->value;

        return Lang::has($key)
            ? Lang::get($key, ['support_email' => Config::string('takes.support_email')])
            : $this->value;
    }
}
