<?php

namespace App\Providers;

use App\Contracts\Billing\PixelforgeUsageSource;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\User;
use App\Services\Billing\UnconfiguredPixelforgeUsageSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
