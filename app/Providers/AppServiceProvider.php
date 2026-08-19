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

        /*
         * Fail loudly everywhere except production: lazy loading, silently
         * discarded attributes and missing attributes all become exceptions,
         * so an N+1 is a failing test rather than a slow page six months later.
         *
         * Deliberately not `isLocal()` only. A mass assignment that drops a
         * non-fillable field says nothing without this, and the test suite is
         * exactly where you want to hear about it — that oversight let a
         * "promote the next credential" path do nothing at all while its own
         * test passed for the wrong reason.
         */
        Model::shouldBeStrict(! $this->app->isProduction());

        // Destructive statements against a live database need a deliberate
        // `--force`, not a mistyped environment variable.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(TenantAiSettings::class, AiSettingsPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Staff::class, StaffPolicy::class);
    }
}
