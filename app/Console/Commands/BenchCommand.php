<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Availability\AvailabilityEngine;
use App\Domain\Availability\AvailabilityQuery;
use App\Domain\Reporting\DayStatistics;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Measures the paths that would hurt first.
 *
 * The point is not the numbers — they depend entirely on the machine. The
 * point is that they are reproducible on yours: every performance claim in the
 * README came out of this command, and anyone who doubts one can run it.
 *
 *     php artisan demo:bench
 */
final class BenchCommand extends Command
{
    protected $signature = 'demo:bench
                            {--runs=10 : Samples per measurement}
                            {--tenant= : Workspace slug, defaults to the demo studio}';

    protected $description = 'Benchmark the availability engine and the diary queries';

    public function handle(TenantContext $tenants, AvailabilityEngine $engine, DayStatistics $statistics): int
    {
        $runs = max(3, (int) $this->option('runs'));
        $slug = $this->option('tenant') ?? config('slotflow.demo.tenant_slug');

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->components->error("No workspace with slug [{$slug}]. Run `php artisan demo:seed` first.");

            return self::FAILURE;
        }

        return $tenants->runFor($tenant, function () use ($tenant, $engine, $statistics, $runs): int {
            $service = Service::query()->active()->ordered()->first();

            if ($service === null) {
                $this->components->error('That workspace has no bookable services.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->components->twoColumnDetail('<options=bold>Workspace</>', $tenant->name);
            $this->components->twoColumnDetail('Bookings on file', number_format(Booking::query()->count()));
            $this->components->twoColumnDetail('Of which upcoming', number_format(Booking::query()->blocking()->upcoming()->count()));
            $this->components->twoColumnDetail('Samples per measurement', (string) $runs);
            $this->newLine();

            $today = CarbonImmutable::now($tenant->timezone)->startOfDay();

            $this->section('Availability — 7 days, all staff, cold');
            $this->measure($runs, function () use ($engine, $service, $today, $tenant): void {
                // Bump the cache version each run so nothing is reused: this is
                // the number that matters when the diary has just changed.
                $engine->invalidate($tenant->id);

                $engine->find(new AvailabilityQuery(
                    service: $service,
                    fromDate: $today,
                    untilDate: $today->addDays(6),
                    timezone: $tenant->timezone,
                ));
            });

            $this->section('Availability — 7 days, warm cache');
            // Prime it once, then measure repeats.
            $engine->find(new AvailabilityQuery(
                service: $service,
                fromDate: $today,
                untilDate: $today->addDays(6),
                timezone: $tenant->timezone,
            ));
            $this->measure($runs, function () use ($engine, $service, $today, $tenant): void {
                $engine->find(new AvailabilityQuery(
                    service: $service,
                    fromDate: $today,
                    untilDate: $today->addDays(6),
                    timezone: $tenant->timezone,
                ));
            });

            $this->section('Availability — 30 days, all staff, cold');
            $this->measure($runs, function () use ($engine, $service, $today, $tenant): void {
                $engine->invalidate($tenant->id);

                $engine->find(new AvailabilityQuery(
                    service: $service,
                    fromDate: $today,
                    untilDate: $today->addDays(29),
                    timezone: $tenant->timezone,
                ));
            });

            $this->section('Admin diary — 25 rows with every relation');
            $this->measure($runs, function (): void {
                Booking::query()
                    ->with(['service', 'staff', 'customer', 'riskAssessment'])
                    ->whereIn('status', BookingStatus::blocking())
                    ->orderByDesc('starts_at')
                    ->limit(25)
                    ->get();
            });

            $this->section('Admin diary — the same 25 rows, lazily loaded');
            $this->measure($runs, function (): void {
                // Deliberately without `with()`: this is the N+1 the eager
                // loading above avoids, kept here so the difference is a
                // measurement rather than a claim.
                $bookings = Booking::query()
                    ->whereIn('status', BookingStatus::blocking())
                    ->orderByDesc('starts_at')
                    ->limit(25)
                    ->get();

                // Touching each relation is what triggers the lazy load, and the
                // lazy load is the thing being measured. Accumulating the values
                // keeps that intent obvious — and keeps a static analyser from
                // reading four bare property reads as dead code.
                $touched = 0;

                foreach ($bookings as $booking) {
                    $touched += mb_strlen($booking->service->name)
                        + mb_strlen($booking->staff->name)
                        + mb_strlen($booking->customer->name)
                        + ($booking->riskAssessment->score ?? 0);
                }
            });

            $this->section('Dashboard statistics for today');
            $this->measure($runs, function () use ($statistics, $tenant): void {
                $statistics->forDay($tenant, CarbonImmutable::now($tenant->timezone));
            });

            $this->newLine();
            $this->components->info('Times are wall-clock on this machine. Compare the rows, not the absolutes.');

            return self::SUCCESS;
        });
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <fg=cyan;options=bold>{$title}</>");
    }

    /**
     * Run a closure `$runs` times, reporting the median duration and the
     * number of SQL queries it issued.
     *
     * Median rather than mean: one cold run with a slow first connection
     * should not become the headline number.
     */
    private function measure(int $runs, callable $callback): void
    {
        $durations = [];
        $queries = 0;

        // Strict mode makes lazy loading fatal in local, which is the whole
        // point of it — but the N+1 comparison below needs to actually perform
        // the lazy loads it is measuring.
        $wasStrict = \Illuminate\Database\Eloquent\Model::preventsLazyLoading();
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);

        try {
            for ($i = 0; $i < $runs; $i++) {
                DB::flushQueryLog();
                DB::enableQueryLog();

                $startedAt = hrtime(true);
                $callback();
                $durations[] = (hrtime(true) - $startedAt) / 1_000_000;

                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
        } finally {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading($wasStrict);
            Cache::forget('nothing');
        }

        sort($durations);
        $median = $durations[intdiv(count($durations), 2)];

        $this->components->twoColumnDetail(
            sprintf('    median %s ms', number_format($median, 1)),
            sprintf('%d %s', $queries, $queries === 1 ? 'query' : 'queries'),
        );
    }
}
