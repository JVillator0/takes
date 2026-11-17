<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * What a customer role may do (spec 3-01, D-015). Permissions never change
 * with the balance: credits are state, checked by policies.
 */
enum CustomerPermission: string
{
    case TakesCreate = 'takes.create';
    case AiSuggest = 'ai.suggest';
    case CreditsPurchase = 'credits.purchase';
    case ProjectsManage = 'projects.manage';
}
