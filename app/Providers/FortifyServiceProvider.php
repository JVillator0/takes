<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\DTOs\Auth\DemoLoginDTO;
use App\Queries\Auth\ListDemoLoginsQuery;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

/**
 * Fortify serves the public app's customers on the `customer` guard
 * (config/fortify.php, spec 3-01). Admins sign in through Filament (spec 7).
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'demoPanel' => $this->demoPanel(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * P02 demo panel (spec 3-01 issue 4, D-045): null unless
     * DEMO_LOGIN_ENABLED=true. "Try a demo account" (POST /demo, spec 4) is
     * always on it then; the seeded logins follow when they exist.
     *
     * @return array{title: string, description: string, adminHint: string, tryDemo: string, logins: list<array{key: string, label: string, email: string, password: string}>}|null
     */
    private function demoPanel(): ?array
    {
        if (! config()->boolean('takes.demo.enabled')) {
            return null;
        }

        $logins = app(ListDemoLoginsQuery::class)->execute();

        return [
            'title' => __('demo.panel_title'),
            'description' => __('demo.panel_description'),
            'adminHint' => __('demo.admin_hint'),
            'tryDemo' => __('landing.hero.demo'),
            'logins' => array_map(fn (DemoLoginDTO $login): array => $login->toArray(), $logins),
        ];
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input(Fortify::username());
            $throttleKey = Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
