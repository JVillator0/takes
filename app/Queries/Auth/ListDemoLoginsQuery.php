<?php

declare(strict_types=1);

namespace App\Queries\Auth;

use App\DTOs\Auth\DemoLoginDTO;
use App\Enums\Auth\DemoAccount;
use App\Models\Customer;
use Illuminate\Support\Facades\Config;

/**
 * The seeded demo logins for the P02 panel (spec 3-01 issue 4): empty unless
 * DEMO_LOGIN_ENABLED=true, and only accounts that were actually seeded, in
 * the DemoAccount order.
 */
final class ListDemoLoginsQuery
{
    /**
     * @return list<DemoLoginDTO>
     */
    public function execute(): array
    {
        if (! Config::boolean('takes.demo.enabled')) {
            return [];
        }

        $seeded = Customer::query()->whereIn('email', DemoAccount::emails())->pluck('email')->all();
        $password = Config::string('takes.demo.password');

        $logins = [];

        foreach (DemoAccount::cases() as $account) {
            if (in_array($account->email(), $seeded, true)) {
                $logins[] = new DemoLoginDTO($account->value, $account->label(), $account->email(), $password);
            }
        }

        return $logins;
    }
}
