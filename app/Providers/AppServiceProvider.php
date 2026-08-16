<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TenantAiSettings;
use App\Policies\AiSettingsPolicy;
use App\Policies\BookingPolicy;
use App\Policies\ServicePolicy;
use App\Policies\StaffPolicy;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Immutable dates by default. `$booking->starts_at->addHour()`
        // silently mutating the model is a bug class this removes outright.
        Date::use(CarbonImmutable::class);

        // Fail loudly in development rather than quietly in production:
        // lazy loading becomes an exception, so an N+1 shows up as a failing
        // test instead of a slow page six months later.
        Model::shouldBeStrict($this->app->isLocal());

        // Destructive statements against a live database need a deliberate
        // `--force`, not a mistyped environment variable.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(TenantAiSettings::class, AiSettingsPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Staff::class, StaffPolicy::class);
    }
}
