<?php

declare(strict_types=1);

namespace App\Filament\Admin\Clusters\Studio\Resources\Takes\Actions;

use App\Actions\Takes\ReconcileTakeNowAction;
use App\Enums\Auth\AdminPermission;
use App\Enums\Takes\ReconcileStatus;
use App\Filament\Admin\Support\Money;
use App\Models\Take;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * **Reconcile now** (A06, D-058): confirms, runs ReconcileTakeNowAction and reports "No change" or what it
 * applied after a completed poll, or, when Pixelforge could not be checked, says so (never a green "No change"). Only admins holding `Reconcile:Take` see it; the auditor never does.
 */
final class ReconcileTakeAction
{
    public static function make(): Action
    {
        return self::configure(Action::make('reconcile'))
            // A succeeded or failed take is final: there is nothing to poll, so the action is not offered.
            ->visible(fn (Take $record): bool => ! $record->status->isTerminal())
            ->action(fn (Take $record) => self::reconcile($record));
    }

    /**
     * The look, confirmation and Shield gate every Reconcile now button shares (A06, the takes table, the A04
     * drill-down rows); callers add visibility and the call to reconcile().
     */
    public static function configure(Action $action): Action
    {
        return $action
            ->label(__('console.takes.reconcile.label'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading(__('console.takes.reconcile.heading'))
            ->modalDescription(__('console.takes.reconcile.description'))
            ->authorize(fn (): bool => self::admin()?->can(AdminPermission::ReconcileTake->value) === true);
    }

    /**
     * Runs Reconcile now on one take and notifies the outcome; shared by A06, the takes table and the A04
     * drill-down rows (D-058).
     */
    public static function reconcile(Take $take): void
    {
        $admin = self::admin();

        if ($admin === null || $admin->cannot(AdminPermission::ReconcileTake->value)) {
            return;
        }

        $statusBefore = $take->refresh()->status;

        if ($statusBefore->isTerminal()) {
            Notification::make()->title(__('console.takes.reconcile.already_final'))->info()->send();

            return;
        }

        $result = app(ReconcileTakeNowAction::class)->execute($admin, $take);
        $checked = in_array($result->status, [ReconcileStatus::Terminal, ReconcileStatus::InFlight], true);

        // Say what was actually checked: only a completed poll may report "No change".
        Notification::make()
            ->title($checked ? self::outcome($take, $statusBefore === $take->status) : __('console.takes.reconcile.unchecked.'.$result->status->value))
            ->status($checked ? 'success' : 'warning')
            ->send();
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
