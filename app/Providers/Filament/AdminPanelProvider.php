<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\Auth\AdminRole;
use App\Filament\Admin\Audit\AdminAuditResource;
use App\Filament\Admin\Auth\SetUpRequiredMultiFactorAuthentication;
use App\Filament\Admin\Clusters\Billing\BillingCluster;
use App\Filament\Admin\Clusters\Providers\ProvidersCluster;
use App\Filament\Admin\Clusters\Studio\StudioCluster;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Http\Middleware\EnsureAdminMultiFactorAuthentication;
use App\Listeners\RecordAdminWrites;
use App\Models\User;
use App\Policies\ActivityPolicy;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Elemind\FilamentECharts\FilamentEChartsPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use pxlrbt\FilamentEnvironmentIndicator\EnvironmentIndicatorPlugin;
use Rmsramos\Activitylog\ActivitylogPlugin;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role;

/**
 * The admin console at /admin (spec 7): admins are the default `users` table on the `web` guard (D-012), with
 * Filament's own login, password reset and authenticator-app MFA, required for every role but the read-only
 * auditor (D-013). Shield gates every resource, page and widget; the sidebar order lives only in navigation().
 * Nothing in the console polls (D-038): the database may sleep between visits.
 */
final class AdminPanelProvider extends PanelProvider
{
    public const string ID = 'admin';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id(self::ID)
            ->path('admin')
            ->authGuard(AdminRole::GUARD)
            ->login()
            ->passwordReset()
            // The closure is the single lever (spec 7). Filament reads it at route registration, with nobody signed in,
            // so it answers true and the routes get the MFA middleware; EnsureAdminMultiFactorAuthentication reads it
            // again per request, with the admin, and lets the auditor through.
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                setUpRequiredAction: SetUpRequiredMultiFactorAuthentication::class,
                isRequired: fn (): bool => self::requiresMultiFactorAuthentication(Filament::auth()->user()),
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminMultiFactorAuthentication::class)
            ->colors(['primary' => Color::Indigo])
            ->databaseNotifications()
            ->databaseNotificationsPolling(null)
            ->discoverClusters(in: app_path('Filament/Admin/Clusters'), for: 'App\\Filament\\Admin\\Clusters')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->pages([Dashboard::class])
            ->widgets([])
            ->globalSearch(false)
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->breadcrumbs(hasNavigationHierarchy: true)
            // The whole sidebar order in one place: no navigationSort anywhere (FI-03).
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => $builder
                ->items([
                    ...self::navigationItemsOf(Dashboard::class),
                    ...self::navigationItemsOf(CustomerResource::class),
                ])
                ->group(NavigationGroup::make(__('console.groups.operations'))->collapsible()->items([
                    ...self::navigationItemsOf(StudioCluster::class),
                    ...self::navigationItemsOf(BillingCluster::class),
                    ...self::navigationItemsOf(ProvidersCluster::class),
                ]))
                ->group(NavigationGroup::make(__('console.groups.system'))->collapsible()->items([
                    ...self::navigationItemsOf(RoleResource::class),
                    ...self::navigationItemsOf(AdminAuditResource::class),
                ])))
            ->plugins([
                FilamentShieldPlugin::make(),
                FilamentEChartsPlugin::make(),
                ActivitylogPlugin::make()
                    ->resource(AdminAuditResource::class)
                    ->datetimeFormat('Y-m-d H:i:s \U\T\C')
                    ->isResourceActionHidden(true)
                    ->label(fn (): string => __('console.audit.entry'))
                    ->pluralLabel(fn (): string => __('console.audit.title')),
                EnvironmentIndicatorPlugin::make()
                    ->visible(true)
                    ->showBadge(true)
                    ->showBorder(true)
                    ->environment(fn (): string => self::environmentLabel())
                    ->color(fn (): array => match (self::environmentLabel()) {
                        'LOCAL' => Color::Sky,
                        'PRODUCTION' => Color::Red,
                        default => Color::Gray,
                    }),
            ])
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): ?View => self::demoAccess())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    public function boot(): void
    {
        // Billing days are UTC; the console shows every time in UTC.
        FilamentTimezone::set('UTC');

        // Shield's super admin may do everything; only an admin (`users`) can hold it. Roles and the audit stay
        // read-only even for it: AdminRole::permissions() is the single source of role definitions.
        Gate::before(fn (object $user, string $ability, array $arguments): ?bool => self::superAdminGate($user, $ability, $arguments));

        Gate::policy(Activity::class, ActivityPolicy::class);

        Event::listen(['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'], [RecordAdminWrites::class, 'handle']);
        Event::listen(
            [PermissionAttachedEvent::class, PermissionDetachedEvent::class, RoleAttachedEvent::class, RoleDetachedEvent::class],
            [RecordAdminWrites::class, 'permissions'],
        );
    }

    /**
     * MFA is required unless the admin is an auditor and nothing more (D-013). With nobody signed in (route
     * registration, the login page) it is required, so the routes carry the middleware.
     */
    public static function requiresMultiFactorAuthentication(?Authenticatable $user): bool
    {
        if (! $user instanceof User) {
            return true;
        }

        return ! $user->hasRole(AdminRole::Auditor->value)
            || $user->hasAnyRole([AdminRole::SuperAdmin->value, AdminRole::Operator->value]);
    }

    /**
     * The super admin's `Gate::before`: true for every ability, except writes on a role or an audit entry, which
     * are false for everyone (the console defines roles in code and never edits its own audit).
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function superAdminGate(object $user, string $ability, array $arguments): ?bool
    {
        $subject = $arguments[0] ?? null;
        $subjectClass = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : null);

        if (in_array($subjectClass, [Role::class, Activity::class], true) && ! in_array($ability, ['viewAny', 'view'], true)) {
            return false;
        }

        return $user instanceof User && $user->hasRole(AdminRole::SuperAdmin->value) ? true : null;
    }

    public static function environmentLabel(): string
    {
        $label = config('admin.environment_label');

        return strtoupper(is_string($label) && $label !== '' ? $label : (string) app()->environment());
    }

    /**
     * The navigation item of a page, cluster or resource, shown only to admins who may open it: the builder does not
     * apply Shield's checks on its own.
     *
     * @param  class-string<Page>|class-string<FilamentResource>  $component
     * @return list<NavigationItem>
     */
    private static function navigationItemsOf(string $component): array
    {
        return array_values(array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(
                fn (): bool => $component::canAccess() && $component::shouldRegisterNavigation(),
            ),
            $component::getNavigationItems(),
        ));
    }

    /**
     * Evaluator access on /admin/login (spec 3-01 review, spec 10): the read-only auditor's credentials, shown only
     * while the demo panel is enabled and the auditor is configured.
     */
    private static function demoAccess(): ?View
    {
        $email = config('admin.auditor.email');
        $password = config('admin.auditor.password');

        if (config('takes.demo.enabled') !== true || ! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            return null;
        }

        return view('filament.admin.demo-access', ['email' => $email, 'password' => $password]);
    }
}
