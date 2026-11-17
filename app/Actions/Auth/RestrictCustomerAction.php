<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Admin\RecordAdminActivityAction;
use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Customers › **Restrict / Unrestrict** (A08, spec 7): switches the customer between the `restricted` and
 * `customer` roles (a permission change, never a balance one, D-015) with a required reason, audited. The shared
 * demo logins keep their own roles (D-072) and are refused.
 */
final readonly class RestrictCustomerAction
{
    public const string RESTRICTED = 'customer_restricted';

    public const string UNRESTRICTED = 'customer_unrestricted';

    public function __construct(
        private AssignCustomerRoleAction $assignRole,
        private RecordAdminActivityAction $recordActivity,
    ) {}

    public function execute(User $admin, Customer $customer, bool $restrict, string $reason): Customer
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Restricting or unrestricting a customer needs a reason.');
        }

        if ($customer->isSharedDemoAccount()) {
            throw new InvalidArgumentException('A shared demo login keeps its own role.');
        }

        return DB::transaction(function () use ($admin, $customer, $restrict, $reason): Customer {
            $this->assignRole->execute($customer, $restrict ? CustomerRole::Restricted : CustomerRole::Customer);

            $this->recordActivity->execute($admin, $restrict ? self::RESTRICTED : self::UNRESTRICTED, $customer, [
                'resource' => $customer->getTable(),
                'record' => (string) $customer->id,
                'reason' => $reason,
            ]);

            return $customer;
        });
    }
}
