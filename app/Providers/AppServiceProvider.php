<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Billing\PixelforgeUsageSource;
use App\Enums\Auth\DenialReason;
use App\Http\Responses\DenialResponse;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\User;
use App\Services\Billing\UnconfiguredPixelforgeUsageSource;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // billing:verify --usage; spec 3-04 rebinds it to the Pixelforge client.
        $this->app->bindIf(PixelforgeUsageSource::class, UnconfiguredPixelforgeUsageSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureRateLimiting();
    }

    /**
     * Route throttles that answer with the `rate_limited` denial (D-050,
     * 2-01 copy): JSON callers get 429 `{code, message}`, Inertia visits go
     * back with the denial like any other.
     *
     * - `takes`: 10 creates per minute per customer (spec 4 "Create take").
     * - `demo`: "Try a demo account" per IP and hour (D-045, D-065).
     */
    protected function configureRateLimiting(): void
    {
        $denied = fn (Request $request, array $headers): Response => DenialResponse::make($request, DenialReason::RateLimited, 429)
            ->withHeaders($headers);

        RateLimiter::for('takes', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('takes:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response($denied));

        RateLimiter::for('demo', fn (Request $request): Limit => Limit::perHour(config()->integer('takes.demo.throttle_per_ip_per_hour'))
            ->by('demo:'.$request->ip())
            ->response($denied));
    }

    /**
     * Short aliases instead of class names in polymorphic columns (spec 3-03 §5):
     * `usage_records.billable_type` is `take` or `suggestion`. Enforced, so an
     * unmapped model fails loudly instead of writing a class name.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'customer' => Customer::class,
            'user' => User::class,
            'take' => Take::class,
            'suggestion' => Suggestion::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
