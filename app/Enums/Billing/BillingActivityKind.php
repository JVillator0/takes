<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * What one line of the billing page's Activity is, in customer terms (spec 6):
 * a usage by feature (never by provider, D-023) or a non-charge ledger entry.
 * Labels live in lang/en/billing.php under `activity.kind`.
 */
enum BillingActivityKind: string
{
    case Image = 'image';
    case AiSuggestion = 'ai_suggestion';
    case Purchase = 'purchase';
    case WelcomeCredit = 'welcome_credit';
    case PromoGrant = 'promo_grant';
    case Adjustment = 'adjustment';
}
