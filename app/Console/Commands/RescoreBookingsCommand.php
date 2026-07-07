<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Risk\NoShowRiskScorer;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Recompute no-show risk for upcoming bookings.
 *
 * A booking's score depends on how far away it is and on the customer's
 * history, and both move while the booking sits in the diary. Scoring once at
 * creation and never again means the flag on a booking made two months ago is
 * describing a world that no longer exists.
 *
 * Scheduled nightly in routes/console.php.
 */
final class RescoreBookingsCommand extends Command
{
    protected $signature = 'slotflow:rescore
                            {--tenant= : Limit to one workspace slug}
                            {--days=30 : How far ahead to look}';

    protected $description = 'Recompute no-show risk for upcoming bookings';

    public function handle(NoShowRiskScorer $scorer, TenantContext $tenants): int
    {
        $days = (int) $this->option('days');

        $workspaces = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($workspaces->isEmpty()) {
            $this->components->error('No matching workspace.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($workspaces as $tenant) {
            $tenants->runFor($tenant, function () use ($scorer, $days, $tenant, &$total): void {
                $bookings = Booking::query()
                    ->with(['customer', 'service', 'staff'])
                    ->whereIn('status', BookingStatus::blocking())
                    ->upcoming()
                    ->where('starts_at', '<=', now()->addDays($days))
                    ->get();

                // Chunked so a workspace with a full diary does not hold the
                // whole set in memory, and so a failure part-way through has
                // still done useful work.
                $bookings->chunk(100)->each(function ($chunk) use ($scorer, &$total): void {
                    foreach ($chunk as $booking) {
                        $scorer->scoreAndStore($booking);
                        $total++;
                    }
                });

                $this->components->twoColumnDetail($tenant->name, (string) $bookings->count().' rescored');
            });
        }

        $this->components->info("Rescored {$total} bookings.");

        return self::SUCCESS;
    }
}
