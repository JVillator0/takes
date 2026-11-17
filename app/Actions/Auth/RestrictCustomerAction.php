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
 * `customer` roles (a permission change, never a balance one, D-015) with a required reason, audited. The
 * direction is the caller's intent (fixed when the modal opened); under the customer lock, a customer that already
 * holds the target role is left alone with no audit entry. The shared demo logins keep their roles (D-072).
 */
final readonly class RestrictCustomerAction
{
    public const string RESTRICTED = 'customer_restricted';

    public const string UNRESTRICTED = 'customer_unrestricted';

    public function __construct(
        private AssignCustomerRoleAction $assignRole,
        private RecordAdminActivityAction $recordActivity,
    ) {}

    /**
     * @return bool whether the role changed; false (no write, no audit) when the customer already holds the target
     *              role, e.g. another operator acted first while this modal was open
     */
    public function execute(User $admin, Customer $customer, bool $restrict, string $reason): bool
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Restricting or unrestricting a customer needs a reason.');
        }

        if ($customer->isSharedDemoAccount()) {
            throw new InvalidArgumentException('A shared demo login keeps its own role.');
        }

        return DB::transaction(function () use ($admin, $customer, $restrict, $reason): bool {
            // Serialize concurrent restricts of one customer; the role is read again under the lock.
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->unsetRelation('roles');
            $target = $restrict ? CustomerRole::Restricted : CustomerRole::Customer;

            if ($customer->getRoleNames()->all() === [$target->value]) {
                return false;
            }

            $this->assignRole->execute($customer, $target);

            $this->recordActivity->execute($admin, $restrict ? self::RESTRICTED : self::UNRESTRICTED, $customer, [
                'resource' => $customer->getTable(),
                'record' => (string) $customer->id,
                'reason' => $reason,
            ]);

            return true;
        });
    }
}
