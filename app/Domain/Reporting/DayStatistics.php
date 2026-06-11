<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\BookingStatus;
use App\Enums\RiskBand;
use App\Models\Booking;
use App\Models\Staff;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The numbers behind the dashboard and the morning briefing.
 *
 * Computed here, in SQL and PHP, and then *given* to the model. Asking a
 * language model to add up a day's takings is asking it to do the one thing
 * it is worst at, in the one place where being 8% wrong is unacceptable.
 */
final class DayStatistics
{
    /**
     * @return array{
     *     date: string, booking_count: int, high_risk_count: int,
     *     revenue_cents: int, currency: string, utilisation_percent: int,
     *     first_start: string|null, last_end: string|null,
     *     largest_gap_minutes: int, largest_gap_start: string|null,
     *     busiest_staff: string|null, quiet_staff: string|null,
     *     per_staff: list<array{name: string, bookings: int, booked_minutes: int}>
     * }
     */
    public function forDay(Tenant $tenant, CarbonImmutable $date): array
    {
        $timezone = $tenant->timezone;
        $localDay = $date->setTimezone($timezone)->startOfDay();
        $from = $localDay->utc();
        $until = $localDay->addDay()->utc();

        /** @var Collection<int, Booking> $bookings */
        $bookings = Booking::query()
            ->where('tenant_id', $tenant->id)
            ->with(['staff:id,name', 'service:id,name,duration_minutes', 'riskAssessment'])
            ->whereIn('status', BookingStatus::blocking())
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $until)
            ->orderBy('starts_at')
            ->get();

        $perStaff = $this->perStaff($bookings);
        $gap = $this->largestGap($bookings, $timezone);

        return [
            'date' => $localDay->toDateString(),
            'booking_count' => $bookings->count(),
            'high_risk_count' => $bookings
                ->filter(fn (Booking $b) => $b->riskAssessment?->band === RiskBand::High)
                ->count(),
            'revenue_cents' => (int) $bookings->sum('price_cents'),
            'currency' => $tenant->currency,
            'utilisation_percent' => $this->utilisation($tenant, $bookings, $localDay),
            'first_start' => $bookings->first()?->starts_at->setTimezone($timezone)->format('H:i'),
            'last_end' => $bookings->last()?->ends_at->setTimezone($timezone)->format('H:i'),
            'largest_gap_minutes' => $gap['minutes'],
            'largest_gap_start' => $gap['start'],
            'busiest_staff' => $perStaff[0]['name'] ?? null,
            'quiet_staff' => count($perStaff) > 1 ? $perStaff[count($perStaff) - 1]['name'] : null,
            'per_staff' => $perStaff,
        ];
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<array{name: string, bookings: int, booked_minutes: int}>
     */
    private function perStaff(Collection $bookings): array
    {
        /** @var list<array{name: string, bookings: int, booked_minutes: int}> $rows */
        $rows = $bookings
            ->groupBy('staff_id')
            ->map(function (Collection $group): array {
                /** @var Booking $first */
                $first = $group->first();

                return [
                    'name' => $first->staff->name,
                    'bookings' => $group->count(),
                    'booked_minutes' => (int) $group->sum(
                        fn (Booking $b): float => $b->starts_at->diffInMinutes($b->ends_at)
                    ),
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['booked_minutes'])
            ->values()
            ->all();

        return $rows;
    }

    /**
     * The longest stretch of dead time between the first and last appointment
     * — the gap a walk-in could fill.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array{minutes: int, start: string|null}
     */
    private function largestGap(Collection $bookings, string $timezone): array
    {
        if ($bookings->count() < 2) {
            return ['minutes' => 0, 'start' => null];
        }

        $best = 0;
        $bestStart = null;

        // Gaps are per staff member: two people working in parallel do not
        // leave a gap just because their appointments do not line up.
        foreach ($bookings->groupBy('staff_id') as $group) {
            $ordered = $group->sortBy('starts_at')->values();

            for ($i = 0; $i < $ordered->count() - 1; $i++) {
                $gap = (int) $ordered[$i]->ends_at->diffInMinutes($ordered[$i + 1]->starts_at);

                if ($gap > $best) {
                    $best = $gap;
                    $bestStart = $ordered[$i]->ends_at->setTimezone($timezone)->format('H:i');
                }
            }
        }

        return ['minutes' => $best, 'start' => $bestStart];
    }

    /**
     * Booked minutes as a share of the staff hours actually published for that
     * weekday. Capacity that nobody was rostered for is not idle capacity.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    private function utilisation(Tenant $tenant, Collection $bookings, CarbonImmutable $localDay): int
    {
        $capacity = 0;

        $staff = Staff::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->with(['availabilityRules' => fn ($q) => $q->where('weekday', $localDay->dayOfWeek)])
            ->get();

        foreach ($staff as $member) {
            foreach ($member->availabilityRules as $rule) {
                if (! $rule->appliesOn($localDay)) {
                    continue;
                }

                $start = CarbonImmutable::parse($localDay->toDateString().' '.$rule->starts_at, $member->timezone);
                $end = CarbonImmutable::parse($localDay->toDateString().' '.$rule->ends_at, $member->timezone);

                if ($end->lessThanOrEqualTo($start)) {
                    $end = $end->addDay();
                }

                $capacity += (int) $start->diffInMinutes($end);
            }
        }

        if ($capacity === 0) {
            return 0;
        }

        $booked = (int) $bookings->sum(fn (Booking $b) => $b->starts_at->diffInMinutes($b->ends_at));

        return (int) min(100, round($booked / $capacity * 100));
    }
}
