<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\Actions;

use App\Actions\Auth\RestrictCustomerAction as RestrictCustomer;
use App\Enums\Auth\AdminPermission;
use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * **Restrict / Unrestrict** (A08, spec 7): one action whose label follows the customer's role; confirmation and a
 * required reason; only admins with `Restrict:Customer`, never on a shared demo login (D-072).
 */
final class RestrictCustomerAction
{
    public static function make(): Action
    {
        return Action::make('restrict')
            ->label(fn (Customer $record): string => self::isRestricted($record) ? __('console.customers.restrict.unrestrict_label') : __('console.customers.restrict.label'))
            ->icon(fn (Customer $record): Heroicon => self::isRestricted($record) ? Heroicon::OutlinedLockOpen : Heroicon::OutlinedNoSymbol)
            ->color(fn (Customer $record): string => self::isRestricted($record) ? 'gray' : 'danger')
            ->authorize(fn (): bool => self::admin()?->can(AdminPermission::RestrictCustomer->value) === true)
            ->visible(fn (Customer $record): bool => ! $record->isSharedDemoAccount())
            ->requiresConfirmation()
            ->modalHeading(fn (Customer $record): string => self::isRestricted($record) ? __('console.customers.restrict.unrestrict_heading') : __('console.customers.restrict.heading'))
            ->modalDescription(fn (Customer $record): string => self::isRestricted($record) ? __('console.customers.restrict.unrestrict_description') : __('console.customers.restrict.description'))
            ->schema([
                Textarea::make('reason')->label(__('console.customers.restrict.reason'))->required()->maxLength(500),
            ])
            ->action(function (Customer $record, array $data): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                $restrict = ! self::isRestricted($record);
                app(RestrictCustomer::class)->execute($admin, $record, $restrict, (string) $data['reason']);

                Notification::make()->success()
                    ->title($restrict ? __('console.customers.restrict.restricted') : __('console.customers.restrict.unrestricted'))
                    ->send();
            });
    }

    private static function isRestricted(Customer $customer): bool
    {
        return $customer->hasRole(CustomerRole::Restricted->value);
    }

    private static function admin(): ?User
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User ? $admin : null;
    }
}
