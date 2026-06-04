<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Computes free slots from rules, at request time.
 *
 *      free = working hours − time off − booked (incl. buffers)
 *
 * There is deliberately no `slots` table. Pre-generating rows means every
 * change to a staff member's hours, every new service length and every DST
 * transition needs a backfill — and the day someone forgets one, the system
 * quietly sells time that does not exist.
 *
 * Full write-up, including worked examples and the DST cases: docs/AVAILABILITY.md
 */
final class AvailabilityEngine
{
    /**
     * Free slots for a query, ordered by time then staff.
     *
     * @return list<Slot>
     */
    public function find(AvailabilityQuery $query): array
    {
        $ttl = (int) config('slotflow.availability.cache_ttl', 60);

        if ($ttl <= 0) {
            return $this->compute($query);
        }

        return Cache::remember(
            $this->cacheKey($query),
            $ttl,
            fn (): array => $this->compute($query),
        );
    }

    /**
     * Is this exact start time still free for this staff member?
     *
     * Used as a cheap pre-check before the transactional booking guard. It is
     * *not* the guard itself — between this call and the insert, someone else
     * can take the slot. See App\Domain\Booking\BookingService.
     */
    public function isSlotFree(Service $service, Staff $staff, CarbonImmutable $startsAt): bool
    {
        $block = TimeRange::fromMinutes($startsAt->utc(), $service->blockingMinutes());

        if (! $this->fitsWorkingHours($staff, $block)) {
            return false;
        }

        if ($this->timeOffBlockers($staff, $block)->isNotEmpty()) {
            return false;
        }

        return ! $this->conflictingBookingsQuery($staff, $block)->exists();
    }

    /**
     * @return list<Slot>
     */
    private function compute(AvailabilityQuery $query): array
    {
        $window = $this->clampedWindow($query);

        if ($window === null) {
            return [];
        }

        $service = $query->service;

        /** @var Collection<int, Staff> $staffMembers */
        $staffMembers = $service->staff()
            ->active()
            ->ordered()
            ->when($query->staffId !== null, fn ($q) => $q->where('staff.id', $query->staffId))
            ->get();

        $slots = [];

        foreach ($staffMembers as $staff) {
            $free = TimeRange::subtractAll(
                $this->workingRanges($staff, $window),
                $this->blockers($staff, $window),
            );

            foreach ($this->slotsFromRanges($free, $service, $staff, $window, $query->timezone) as $slot) {
                $slots[] = $slot;
            }
        }

        usort($slots, fn (Slot $a, Slot $b) => [$a->startsAt, $a->staffName] <=> [$b->startsAt, $b->staffName]);

        return $slots;
    }

    /**
     * Narrow the requested window to what is actually bookable: no earlier
     * than "now + minimum notice", no later than the advance-booking limit.
     */
    private function clampedWindow(AvailabilityQuery $query): ?TimeRange
    {
        $tenant = $query->service->tenantModel();
        $window = $query->window();

        $earliest = CarbonImmutable::now('UTC')
            ->addMinutes((int) $tenant->setting('booking.min_notice_minutes', 120));

        $latest = CarbonImmutable::now('UTC')
            ->addDays((int) $tenant->setting('booking.max_advance_days', 60))
            ->endOfDay();

        $start = $window->start->greaterThan($earliest) ? $window->start : $earliest;
        $end = $window->end->lessThan($latest) ? $window->end : $latest;

        return $end->greaterThan($start) ? new TimeRange($start, $end) : null;
    }

    /**
     * Expand weekly rules into absolute UTC ranges covering the window.
     *
     * Iteration happens over calendar dates *in the staff member's timezone*,
     * because that is the clock the rules are written in. "Tuesday 09:00" is a
     * different instant in Vienna and in Dhaka, and a different instant again
     * in Vienna in July than in January.
     *
     * @return list<TimeRange>
     */
    private function workingRanges(Staff $staff, TimeRange $window): array
    {
        $timezone = $staff->timezone;

        /** @var Collection<int, AvailabilityRule> $rules */
        $rules = $staff->availabilityRules()->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $rulesByWeekday = $rules->groupBy('weekday');

        // Start a day early: an overnight rule (22:00–02:00) that begins on the
        // day before the window can still contribute time inside it.
        $cursor = $window->start->setTimezone($timezone)->startOfDay()->subDay();
        $lastDate = $window->end->setTimezone($timezone)->startOfDay();

        $ranges = [];

        while ($cursor->lessThanOrEqualTo($lastDate)) {
            /** @var Collection<int, AvailabilityRule> $applicable */
            $applicable = $rulesByWeekday->get($cursor->dayOfWeek, collect());

            foreach ($applicable as $rule) {
                if (! $rule->appliesOn($cursor)) {
                    continue;
                }

                $range = $this->ruleToRange($rule, $cursor, $timezone);

                if ($range === null || ! $range->overlaps($window)) {
                    continue;
                }

                // Clip to the window so slot generation never runs outside it.
                $ranges[] = new TimeRange(
                    $range->start->greaterThan($window->start) ? $range->start : $window->start,
                    $range->end->lessThan($window->end) ? $range->end : $window->end,
                );
            }

            $cursor = $cursor->addDay();
        }

        return TimeRange::merge($ranges);
    }

    /**
     * Turn "Tuesdays 09:00–13:00" on a specific date into a UTC interval.
     *
     * Two things this handles that a naive implementation does not:
     *
     *  - Overnight rules. `ends_at <= starts_at` means the shift runs past
     *    midnight (22:00–02:00), so the end belongs to the next day.
     *  - DST. Parsing wall-clock time in the staff timezone lets the timezone
     *    database decide what the instant is. On a spring-forward day the
     *    window is genuinely an hour shorter, which is correct: those minutes
     *    did not exist.
     */
    private function ruleToRange(AvailabilityRule $rule, CarbonImmutable $date, string $timezone): ?TimeRange
    {
        $day = $date->toDateString();

        $start = CarbonImmutable::parse("{$day} {$rule->starts_at}", $timezone);
        $end = CarbonImmutable::parse("{$day} {$rule->ends_at}", $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        $startUtc = $start->utc();
        $endUtc = $end->utc();

        // A rule whose entire span falls inside a spring-forward gap collapses
        // to nothing. Returning null is more honest than a zero-length range.
        return $endUtc->greaterThan($startUtc) ? new TimeRange($startUtc, $endUtc) : null;
    }

    /**
     * Everything that removes time from the working hours.
     *
     * @return list<TimeRange>
     */
    private function blockers(Staff $staff, TimeRange $window): array
    {
        $blockers = [];

        foreach ($this->timeOffBlockers($staff, $window) as $timeOff) {
            $blockers[] = new TimeRange($timeOff->starts_at->utc(), $timeOff->ends_at->utc());
        }

        // `blocks_until`, not `ends_at`: an existing appointment reserves its
        // duration *plus* the buffer that was in force when it was booked.
        foreach ($this->conflictingBookingsQuery($staff, $window)->get(['starts_at', 'blocks_until']) as $booking) {
            $blockers[] = new TimeRange(
                CarbonImmutable::parse($booking->starts_at)->utc(),
                CarbonImmutable::parse($booking->blocks_until)->utc(),
            );
        }

        return TimeRange::merge($blockers);
    }

    /**
     * @return Collection<int, TimeOff>
     */
    private function timeOffBlockers(Staff $staff, TimeRange $window): Collection
    {
        /** @var Collection<int, TimeOff> $timeOff */
        $timeOff = $staff->timeOff()
            ->where('starts_at', '<', $window->end)
            ->where('ends_at', '>', $window->start)
            ->get();

        return $timeOff;
    }

    /**
     * The overlap predicate, in one place, used by both the read path
     * (availability) and the write path (the booking guard).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Booking, Staff>
     */
    private function conflictingBookingsQuery(Staff $staff, TimeRange $window)
    {
        return $staff->bookings()
            ->blocking()
            ->where('starts_at', '<', $window->end)
            ->where('blocks_until', '>', $window->start);
    }

    private function fitsWorkingHours(Staff $staff, TimeRange $block): bool
    {
        foreach ($this->workingRanges($staff, $block) as $range) {
            if ($range->contains($block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk each free range on the tenant's slot grid.
     *
     * The grid is anchored to the local midnight of the staff member's day, so
     * a range that begins at an awkward 09:07 (because the previous
     * appointment's buffer ended there) still yields 09:15, 09:30 … rather
     * than 09:07, 09:22.
     *
     * A candidate is offered only if its *whole blocking window* — duration
     * plus buffer — fits inside the free range. Requiring the buffer to fit is
     * what makes the last slot of the day honest: the staff member is still
     * cleaning up at closing time.
     *
     * @param  list<TimeRange>  $ranges
     * @return list<Slot>
     */
    private function slotsFromRanges(
        array $ranges,
        Service $service,
        Staff $staff,
        TimeRange $window,
        string $displayTimezone,
    ): array {
        $granularity = max(5, (int) $service->tenantModel()->setting('booking.slot_granularity_minutes', 15));
        $duration = $service->duration_minutes;
        $blocking = $service->blockingMinutes();

        $slots = [];

        foreach ($ranges as $range) {
            $cursor = $this->alignToGrid($range->start, $granularity, $staff->timezone);

            while ($cursor->addMinutes($blocking)->lessThanOrEqualTo($range->end)) {
                if ($cursor->greaterThanOrEqualTo($window->start)) {
                    $slots[] = new Slot(
                        startsAt: $cursor,
                        endsAt: $cursor->addMinutes($duration),
                        staffId: $staff->id,
                        staffName: $staff->name,
                        displayTimezone: $displayTimezone,
                    );
                }

                $cursor = $cursor->addMinutes($granularity);
            }
        }

        return $slots;
    }

    /**
     * Round an instant up to the next grid boundary of the local day.
     */
    private function alignToGrid(CarbonImmutable $instant, int $granularity, string $timezone): CarbonImmutable
    {
        $local = $instant->setTimezone($timezone);
        $dayStart = $local->startOfDay();

        $step = $granularity * 60;
        $elapsed = (int) $dayStart->diffInSeconds($local);
        $aligned = (int) (ceil($elapsed / $step) * $step);

        return $dayStart->addSeconds($aligned)->utc();
    }

    private function cacheKey(AvailabilityQuery $query): string
    {
        $tenantId = $query->service->tenant_id;
        $version = Cache::get($this->versionKey($tenantId), 1);

        return "availability:{$tenantId}:v{$version}:{$query->cacheFragment()}";
    }

    private function versionKey(int $tenantId): string
    {
        return "availability:version:{$tenantId}";
    }

    /**
     * Invalidate every cached answer for a tenant.
     *
     * Bumping a version counter beats deleting keys: it is one atomic write,
     * it cannot miss a key, and stale entries expire on their own TTL.
     * Called whenever a booking, rule or time-off row changes.
     */
    public function invalidate(int $tenantId): void
    {
        Cache::increment($this->versionKey($tenantId));

        // `increment` is a no-op on a missing key in some stores; seed it.
        if (Cache::get($this->versionKey($tenantId)) === null) {
            Cache::forever($this->versionKey($tenantId), 2);
        }
    }
}
