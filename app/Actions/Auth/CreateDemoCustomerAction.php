<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Billing\GrantWelcomeCreditAction;
use App\Actions\Projects\CreateProjectAction;
use App\DTOs\Auth\DemoSessionDTO;
use App\DTOs\Projects\ProjectNameDTO;
use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Try a demo account" (D-045, spec 4 "Demo account"): a fresh ephemeral
 * customer, never shared, with the welcome credit and an empty project, in
 * one transaction. It expires after `takes.demo.ttl_hours` (D-065); the
 * customer guard middleware logs it out lazily then (no scheduler, no hard
 * delete). Its password is random and never shown, so the account cannot
 * be signed into again once the session ends.
 */
final readonly class CreateDemoCustomerAction
{
    public function __construct(
        private GrantWelcomeCreditAction $grantWelcomeCredit,
        private CreateProjectAction $createProject,
    ) {}

    public function execute(): DemoSessionDTO
    {
        return DB::transaction(function (): DemoSessionDTO {
            $suffix = Str::lower((string) Str::ulid());

            $customer = new Customer;
            $customer->forceFill([
                'name' => __('demo.ephemeral_name'),
                'email' => "demo-{$suffix}@demo.invalid",
                'password' => Str::password(40),
                'is_demo' => true,
                'demo_expires_at' => now()->addHours(config()->integer('takes.demo.ttl_hours')),
            ])->save();

            $customer->assignRole(CustomerRole::Customer->value);
            $this->grantWelcomeCredit->execute($customer);

            $project = $this->createProject->execute($customer, new ProjectNameDTO(__('takes.projects.untitled')));

            return new DemoSessionDTO($customer->refresh(), $project);
        });
    }
}
