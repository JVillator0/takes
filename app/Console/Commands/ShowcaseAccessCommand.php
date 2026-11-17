<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\AssignCustomerRoleAction;
use App\Enums\Auth\CustomerRole;
use App\Enums\Auth\DemoAccount;
use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * The showcase customer is read-only (role `restricted`, D-072). Spec 9's
 * smoke test unlocks it (`customer` role) to create its real history, then
 * locks it again.
 */
final class ShowcaseAccessCommand extends Command
{
    /** @var string */
    protected $signature = 'takes:showcase
        {--unlock : Give the showcase customer the customer role (can create takes)}
        {--lock : Make the showcase customer read-only again (restricted role)}';

    /** @var string */
    protected $description = 'Lock or unlock the showcase customer (D-072)';

    public function handle(AssignCustomerRoleAction $assignRole): int
    {
        $unlock = (bool) $this->option('unlock');
        $lock = (bool) $this->option('lock');

        if ($unlock === $lock) {
            $this->error('Pass exactly one of --unlock or --lock.');

            return self::INVALID;
        }

        $showcase = Customer::query()->where('email', DemoAccount::Showcase->email())->first();

        if ($showcase === null) {
            $this->error('The showcase customer does not exist. Run `php artisan db:seed --class=DemoAccountsSeeder` first.');

            return self::FAILURE;
        }

        $role = $unlock ? CustomerRole::Customer : CustomerRole::Restricted;
        $assignRole->execute($showcase, $role);

        $this->info("Showcase customer is now {$role->value}.");

        return self::SUCCESS;
    }
}
