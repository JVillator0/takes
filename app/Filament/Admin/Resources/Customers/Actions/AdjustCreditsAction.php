<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers\Actions;

use App\Actions\Billing\AdjustCustomerCreditsAction;
use App\Enums\Auth\AdminPermission;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Models\Customer;
use App\Models\User;
use App\Support\Money\Nanodollars;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * **Adjust credits** (A05 modal, normative): a signed USD amount and a required reason; writes one ledger
 * `adjustment` through AdjustCustomerCreditsAction. Only admins with `AdjustCredits:Customer` see it.
 */
final class AdjustCreditsAction
{
    public const string AMOUNT_PATTERN = '/^[+-]?\d+(\.\d{1,9})?$/';

    public static function make(): Action
    {
        return Action::make('adjustCredits')
            ->label(__('console.customers.adjust.label'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->authorize(fn (): bool => self::admin()?->can(AdminPermission::AdjustCreditsCustomer->value) === true)
            ->modalHeading(__('console.customers.adjust.label'))
            ->modalSubmitActionLabel(__('console.customers.adjust.submit'))
            ->schema([
                TextInput::make('amount')
                    ->label(__('console.customers.adjust.amount'))
                    ->helperText(__('console.customers.adjust.amount_help'))
                    ->required()
                    ->regex(self::AMOUNT_PATTERN)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && preg_match(self::AMOUNT_PATTERN, $value) === 1 && self::parse($value)->isZero()) {
                            $fail(__('console.customers.adjust.invalid_amount'));
                        }
                    })
                    ->validationMessages(['regex' => __('console.customers.adjust.invalid_amount')]),
                Textarea::make('reason')
                    ->label(__('console.customers.adjust.reason'))
                    ->required()
                    ->maxLength(500),
            ])
            ->action(function (Customer $record, array $data, Action $action): void {
                $admin = self::admin();

                if ($admin === null) {
                    return;
                }

                $amount = self::parse((string) $data['amount']);

                try {
                    app(AdjustCustomerCreditsAction::class)->execute($admin, $record, $amount, (string) $data['reason']);
                } catch (InsufficientCreditsException $exception) {
                    Notification::make()->danger()
                        ->title(__('console.customers.adjust.insufficient', ['available' => $exception->available->formatExact()]))
                        ->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title(__('console.customers.adjust.done', [
                        'amount' => $amount->formatExact(),
                        'balance' => $record->refresh()->balance()->formatExact(),
                    ]))
                    ->send();
            });
    }

    /** "+2.00" → 2,000,000,000 nanodollars; the leading "+" is optional. */
    public static function parse(string $amount): Nanodollars
    {
        return Nanodollars::fromDecimalString(ltrim(trim($amount), '+'));
    }

    private static function admin(): ?User
    {
        $admin = Filament::auth()->user();

        return $admin instanceof User ? $admin : null;
    }
}
