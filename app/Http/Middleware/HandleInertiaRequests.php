<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTOs\Auth\AbilityDTO;
use App\Http\Resources\Billing\CreditPackResource;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Purchase;
use App\Queries\Billing\ListCreditPacksQuery;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * Lang groups shared with the client as UI copy (D-057). They hold no
     * secrets and no provider names (D-023).
     */
    private const COPY_GROUPS = ['takes', 'billing', 'landing', 'suggestions'];

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
            // Only the boolean, never the key (spec 3-04 issue 4). Spec 4 shows the
            // "service not configured" banner and disables submit when false.
            'pixelforge' => [
                'configured' => app(PixelforgeClient::class)->isConfigured(),
            ],
            // Buy credits from any customer page (D-026): the packs of spec 3-03
            // as the billing page shows them (spec 6), exact strings.
            'creditPacks' => fn (): array => CreditPackResource::collection(app(ListCreditPacksQuery::class)->execute())->resolve($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'copy' => Inertia::once(fn (): array => self::copy())->as('copy:'.self::copyVersion()),
        ];
    }

    /**
     * Customer-facing UI copy read on the client by `useCopy().t()` (D-057).
     *
     * An Inertia once prop: sent on the first page load and kept by the client
     * across visits. Its once key carries copyVersion(), so a deploy that
     * changes only copy re-sends it to tabs that are already open; the asset
     * version does not cover lang files.
     *
     * @return array<string, mixed>
     */
    private static function copy(): array
    {
        $copy = [];

        foreach (self::COPY_GROUPS as $group) {
            $copy[$group] = trans($group);
        }

        return $copy;
    }

    /**
     * Version of the shared copy in the active locale: the locale plus the
     * modification time of each lang file. A deploy that changes a string
     * writes a new file, so the version changes and open tabs get the new
     * copy. It costs three stat calls per request: no read, no hash, no cache.
     */
    public static function copyVersion(): string
    {
        $mtimes = array_map(
            fn (string $group): int => File::lastModified(self::copyPath($group)),
            self::COPY_GROUPS,
        );

        return app()->getLocale().'-'.implode('-', $mtimes);
    }

    /**
     * The group's file in the active locale, or the fallback locale when the
     * active one has no such file (the same lookup trans() falls back to).
     */
    private static function copyPath(string $group): string
    {
        $path = lang_path(app()->getLocale()."/{$group}.php");

        return File::exists($path)
            ? $path
            : lang_path(config()->string('app.fallback_locale')."/{$group}.php");
    }

    private function customer(Request $request): ?Customer
    {
        $customer = $request->user('customer');

        return $customer instanceof Customer ? $customer : null;
    }

    /**
     * Money as server-formatted strings: 4 decimals for the balance pill and
     * the exact value for its tooltip (D-042); the frontend never does money
     * math. `available = balance − held` (D-024).
     *
     * @return array{id: int, name: string, email: string, available: string, available_exact: string, on_hold: string, on_hold_exact: string}|null
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
            'available_exact' => $customer->available()->formatExact(),
            'on_hold' => $customer->held()->format(),
            'on_hold_exact' => $customer->held()->formatExact(),
        ];
    }

    /**
     * Estimate-independent abilities shared by every customer page. Abilities
     * that depend on an estimate (`createTake`, `suggest`) are per-page props
     * built the same way by the page that has the estimate (specs 4 and 8).
     *
     * @return array{createProject: array{allowed: bool, reason: string|null, message: string|null}, purchaseCredits: array{allowed: bool, reason: string|null, message: string|null}, updateAccount: array{allowed: bool, reason: string|null, message: string|null}}|null
     */
    private function abilities(?Customer $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        $gate = Gate::forUser($customer);

        return [
            'createProject' => AbilityDTO::fromResponse($gate->inspect('create', Project::class))->toArray(),
            'purchaseCredits' => AbilityDTO::fromResponse($gate->inspect('create', Purchase::class))->toArray(),
            'updateAccount' => AbilityDTO::fromResponse($gate->inspect('updateAccount', $customer))->toArray(),
        ];
    }
}
