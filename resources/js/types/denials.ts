/** Mirrors `App\Enums\Auth\DenialReason` (D-050). Copy comes from the server. */
export type DenialReason =
    | 'insufficient_credits'
    | 'account_restricted'
    | 'daily_take_cap'
    | 'demo_quota_reached'
    | 'rate_limited'
    | 'ai_paused'
    | 'ai_daily_limit';
