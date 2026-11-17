<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A read-only console resource authorized by its Shield permission keys (`ViewAny:Take`, `View:Take`) instead of
 * the model policy (D-074): the model policies belong to the customer guard (TakePolicy, ProjectPolicy take a
 * Customer), and a model has only one policy. Every Filament check goes through getAuthorizationResponse(), so
 * viewing needs the matching permission and nothing can be created, edited or deleted from the console.
 */
trait ReadsWithShieldPermissions
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $affix = match ($action instanceof UnitEnum ? $action->name : $action) {
            'viewAny' => 'ViewAny',
            'view' => 'View',
            default => null,
        };

        $admin = Filament::auth()->user();

        return $affix !== null && $admin instanceof User && $admin->can($affix.':'.class_basename(static::getModel()))
            ? Response::allow()
            : Response::deny();
    }
}
