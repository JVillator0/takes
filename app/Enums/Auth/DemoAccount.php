<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * Seeded demo logins listed on the P02 panel (spec 3-01 issue 4, D-045).
 * Each account exists to show one behavior; the password is the public
 * `takes.demo.password`. The ephemeral "Try a demo account" customers are
 * spec 4 and are not listed here.
 */
enum DemoAccount: string
{
    /** Read-mostly real history (README credentials); topped up by an operator only. */
    case Showcase = 'showcase';

    /** Normal flow, $10.00. */
    case DemoCustomer = 'demo_customer';

    /** $0.01: every take is blocked with `insufficient_credits`. */
    case LowBalance = 'low_balance';

    /** $0.00, no history: empty states. */
    case NewCustomer = 'new_customer';

    public function email(): string
    {
        return match ($this) {
            self::Showcase => 'showcase@example.com',
            self::DemoCustomer => 'demo@example.com',
            self::LowBalance => 'low-balance@example.com',
            self::NewCustomer => 'new@example.com',
        };
    }

    public function customerName(): string
    {
        return match ($this) {
            self::Showcase => 'Showcase customer',
            self::DemoCustomer => 'Demo customer',
            self::LowBalance => 'Low balance',
            self::NewCustomer => 'New customer',
        };
    }

    /** Starting credit in nanodollars (demo dataset, D-037). */
    public function startingCreditNanos(): int
    {
        return match ($this) {
            self::DemoCustomer => 10_000_000_000,
            self::LowBalance => 10_000_000,
            self::Showcase, self::NewCustomer => 0,
        };
    }

    /** Panel label from lang/en/demo.php. */
    public function label(): string
    {
        $label = __('demo.accounts.'.$this->value);

        return is_string($label) ? $label : $this->customerName();
    }

    /**
     * @return list<string>
     */
    public static function emails(): array
    {
        return array_map(fn (self $account): string => $account->email(), self::cases());
    }
}
