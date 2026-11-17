<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Takes\CreatesSuggestedTake;
use App\Enums\Auth\DenialReason;
use App\Http\Responses\DenialResponse;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\User;
use App\Support\Takes\SuggestedTakeCreator;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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
        // Applying an AI suggestion creates its take through spec 4's
        // CreateTakeAction (spec 8 step 4): estimate, hold, submit, reconcile.
        $this->app->bind(CreatesSuggestedTake::class, SuggestedTakeCreator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureRateLimiters();
    }

    /**
     * "Describe a change" requests per customer per minute (D-050): over the
     * limit is a `rate_limited` denial with its 2-01 copy, not an error page.
     */
    protected function configureRateLimiters(): void
    {
        // Spec 4: `rate_limited` denials (D-050, 2-01 copy). JSON callers get
        // 429 `{code, message}`, Inertia visits go back with the denial.
        // `takes`: 10 creates per minute per customer; `demo`: "Try a demo
        // account" per IP and hour (D-045, D-065).
        $denied = fn (Request $request, array $headers): Response => DenialResponse::make($request, DenialReason::RateLimited, 429)
            ->withHeaders($headers);

        RateLimiter::for('takes', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('takes:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response($denied));

        RateLimiter::for('demo', fn (Request $request): Limit => Limit::perHour(Config::integer('takes.demo.throttle_per_ip_per_hour'))
            ->by('demo:'.$request->ip())
            ->response($denied));

        RateLimiter::for('ai-suggestions', fn (Request $request): Limit => Limit::perMinute(Config::integer('takes.ai.throttle_per_minute'))
            ->by('ai-suggestions:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (): JsonResponse => response()->json([
                'reason' => DenialReason::RateLimited->value,
                'message' => DenialReason::RateLimited->message(),
            ], 429)));
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
