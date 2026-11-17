<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTOs\Auth\AbilityDTO;
use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * `auth` is a closure so it resolves at render time, after the
     * route's `customer.guard` / `auth:customer` middleware ran (D-032). The
     * public app only ever shares the customer: never an admin, and never
     * provider cost, tokens, provider names or margin (D-023).
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Shared abilities live under `auth.can`, so a page's own `can`
            // prop (createTake, suggest: specs 4 and 8) never replaces them.
            'auth' => fn (): array => [
                'customer' => $this->customerProps($this->customer($request)),
                'can' => $this->abilities($this->customer($request)),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function customer(Request $request): ?Customer
    {
        $customer = $request->user('customer');

        return $customer instanceof Customer ? $customer : null;
    }

    /**
     * Money as server-formatted strings (4 decimals, the balance pill); the
     * frontend never does money math. `available = balance − held` (D-024).
     *
     * @return array{id: int, name: string, email: string, available: string, on_hold: string}|null
     */
    private function customerProps(?Customer $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'available' => $customer->available()->format(),
            'on_hold' => $customer->held()->format(),
        ];
    }

    /**
     * Estimate-independent abilities shared by every customer page. Abilities
     * that depend on an estimate (`createTake`, `suggest`) are per-page props
     * built the same way by the page that has the estimate (specs 4 and 8).
     *
     * @return array{createProject: array{allowed: bool, reason: string|null, message: string|null}, purchaseCredits: array{allowed: bool, reason: string|null, message: string|null}}|null
     */
    private function abilities(?Customer $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        $gate = Gate::forUser($customer);

        return [
            'createProject' => AbilityDTO::fromResponse($gate->inspect('create', Project::class))->toArray(),
            'purchaseCredits' => AbilityDTO::fromResponse(
                $customer->checkPermissionTo(CustomerPermission::CreditsPurchase->value)
                    ? Response::allow()
                    : DenialReason::AccountRestricted->deny(),
            )->toArray(),
        ];
    }
}
