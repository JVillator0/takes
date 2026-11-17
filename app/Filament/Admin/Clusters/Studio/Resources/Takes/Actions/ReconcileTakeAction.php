<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes\Actions;

use App\Actions\Takes\ReconcileTakeNowAction;
use App\Enums\Auth\AdminPermission;
use App\Filament\Admin\Support\Money;
use App\Models\Take;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * **Reconcile now** (A06, D-058): confirms, runs ReconcileTakeNowAction and reports "No change" or what it
 * applied. Only admins holding `Reconcile:Take` see it; the auditor never does.
 */
final class ReconcileTakeAction
{
    public static function make(): Action
    {
        return Action::make('reconcile')
            ->label(__('console.takes.reconcile.label'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading(__('console.takes.reconcile.heading'))
            ->modalDescription(__('console.takes.reconcile.description'))
            ->authorize(fn (): bool => self::admin()?->can(AdminPermission::ReconcileTake->value) === true)
            ->action(function (Take $record): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                $statusBefore = $record->status;
                $take = app(ReconcileTakeNowAction::class)->execute($admin, $record);

                Notification::make()
                    ->title(self::outcome($take, $statusBefore === $take->status))
                    ->success()
                    ->send();
            });
    }

    private static function outcome(Take $take, bool $unchanged): string
    {
        if ($unchanged) {
            return __('console.takes.reconcile.no_change');
        }

        $charge = $take->usageRecord()->value('charge_nanos');

        return $charge === null
            ? __('console.takes.reconcile.applied', ['state' => $take->status->getLabel()])
            : __('console.takes.reconcile.applied_charged', ['state' => $take->status->getLabel(), 'amount' => Money::exact($charge)]);
    }

    private static function admin(): ?User
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User ? $admin : null;
    }
}
