<?php

declare(strict_types=1);

use App\Domain\Availability\AvailabilityEngine;
use App\Domain\Availability\AvailabilityQuery;
use App\Domain\Availability\Slot;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Tests\Support\StudioFactory;

/**
 * The availability engine.
 *
 * These are the tests that matter most in this project: every bug they would
 * catch shows up to a customer as a slot that was not really free, or a free
 * slot they were never offered.
 */
beforeEach(function (): void {
    $this->engine = app(AvailabilityEngine::class);

    // A fixed "now" so nothing here depends on when it runs. Wednesday.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    config()->set('slotflow.availability.cache_ttl', 0);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function slotsFor(StudioFactory $studio, string $from, ?string $until = null, ?string $tz = null): array
{
    $timezone = $tz ?? $studio->tenant->timezone;

    return app(AvailabilityEngine::class)->find(new AvailabilityQuery(
        service: $studio->service,
        fromDate: CarbonImmutable::parse($from, $timezone)->startOfDay(),
        untilDate: CarbonImmutable::parse($until ?? $from, $timezone)->startOfDay(),
        timezone: $timezone,
    ));
}

/** Local HH:MM strings, for readable assertions. */
function localTimes(array $slots): array
{
    return array_map(fn (Slot $slot) => $slot->localStart()->format('H:i'), $slots);
}

it('returns nothing when the staff member has no hours', function (): void {
    $studio = new StudioFactory;

    expect(slotsFor($studio, '2026-06-11'))->toBe([]);
});

it('walks the working window on the tenant slot grid', function (): void {
    // 60 minute service, 09:00–12:00 Thursday, 15 minute grid.
    $studio = (new StudioFactory(durationMinutes: 60))->withHours([[4, '09:00', '12:00']]);

    expect(localTimes(slotsFor($studio, '2026-06-11')))
        ->toBe(['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00']);
});

it('requires the whole appointment to fit before closing time', function (): void {
    $studio = (new StudioFactory(durationMinutes: 60))->withHours([[4, '09:00', '10:30']]);

    // 09:30 would end at 10:30 exactly, which is fine. 09:45 would not.
    expect(localTimes(slotsFor($studio, '2026-06-11')))->toBe(['09:00', '09:15', '09:30']);
});

it('requires the buffer to fit too', function (): void {
    // A 60 minute service with a 15 minute turnaround needs 75 minutes of room,
    // because the staff member is still clearing up at closing time.
    $studio = (new StudioFactory(durationMinutes: 60, bufferMinutes: 15))
        ->withHours([[4, '09:00', '10:30']]);

    expect(localTimes(slotsFor($studio, '2026-06-11')))->toBe(['09:00', '09:15']);
});

it('offers slots in both shifts of a split day', function (): void {
    $studio = (new StudioFactory(durationMinutes: 120))
        ->withHours([[4, '09:00', '11:00'], [4, '14:00', '16:00']]);

    expect(localTimes(slotsFor($studio, '2026-06-11')))->toBe(['09:00', '14:00']);
});

it('subtracts an existing booking, including its buffer', function (): void {
    $studio = (new StudioFactory(durationMinutes: 60, bufferMinutes: 15))
        ->withHours([[4, '09:00', '13:00']]);

    // Someone is booked 10:00–11:00, and their 15 minute turnaround blocks
    // the diary until 11:15.
    Booking::factory()
        ->startingAt(CarbonImmutable::parse('2026-06-11 10:00', 'Europe/Vienna'), 60, 15)
        ->create([
            'tenant_id' => $studio->tenant->id,
            'service_id' => $studio->service->id,
            'staff_id' => $studio->staff->id,
        ]);

    $times = localTimes(slotsFor($studio, '2026-06-11'));

    // Nothing inside the appointment or its buffer.
    expect($times)->not->toContain('10:00');
    expect($times)->not->toContain('11:00');

    // The free time resumes the moment the turnaround ends.
    expect($times)->toContain('11:15');

    // And nothing at all before it. The 09:00–10:00 gap is exactly 60 minutes,
    // which fits the appointment but not the 15 minutes of clearing up that
    // has to follow it — so the gap is unbookable for this service. That is
    // the point of a buffer: booking into it would leave no turnaround.
    expect($times)->not->toContain('09:00');
    expect($times[0])->toBe('11:15');
});

it('offers a gap that fits the appointment and its buffer', function (): void {
    // The same shape as above, but the gap is 75 minutes rather than 60.
    $studio = (new StudioFactory(durationMinutes: 60, bufferMinutes: 15))
        ->withHours([[4, '09:00', '13:00']]);

    Booking::factory()
        ->startingAt(CarbonImmutable::parse('2026-06-11 10:15', 'Europe/Vienna'), 60, 15)
        ->create([
            'tenant_id' => $studio->tenant->id,
            'service_id' => $studio->service->id,
            'staff_id' => $studio->staff->id,
        ]);

    $times = localTimes(slotsFor($studio, '2026-06-11'));

    expect($times)->toContain('09:00');
    expect($times)->not->toContain('09:15');   // would finish clearing at 10:30
});

it('ignores cancelled and missed bookings when computing free time', function (): void {
    $studio = (new StudioFactory(durationMinutes: 60))->withHours([[4, '09:00', '11:00']]);

    foreach ([BookingStatus::Cancelled, BookingStatus::NoShow] as $status) {
        Booking::factory()
            ->startingAt(CarbonImmutable::parse('2026-06-11 09:00', 'Europe/Vienna'), 60)
            ->status($status)
            ->create([
                'tenant_id' => $studio->tenant->id,
                'service_id' => $studio->service->id,
                'staff_id' => $studio->staff->id,
            ]);
    }

    // The row is kept as history; the time is not.
    expect(localTimes(slotsFor($studio, '2026-06-11')))->toContain('09:00');
});

it('subtracts time off', function (): void {
    $studio = (new StudioFactory(durationMinutes: 60))->withHours([[4, '09:00', '13:00']]);

    TimeOff::factory()->create([
        'tenant_id' => $studio->tenant->id,
        'staff_id' => $studio->staff->id,
        'starts_at' => CarbonImmutable::parse('2026-06-11 10:00', 'Europe/Vienna')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-06-11 12:00', 'Europe/Vienna')->utc(),
    ]);

    $times = localTimes(slotsFor($studio, '2026-06-11'));

    expect($times)->toContain('09:00');
    expect($times)->not->toContain('10:00');
    expect($times)->not->toContain('11:00');
    expect($times)->toContain('12:00');
});

it('respects the minimum notice period', function (): void {
    $studio = (new StudioFactory(durationMinutes: 60))->withHours([[3, '00:00', '23:00']]);

    $studio->tenant->update([
        'settings' => ['booking' => ['min_notice_minutes' => 180, 'slot_granularity_minutes' => 15]],
    ]);

    // "Now" is 06:00 UTC = 08:00 Vienna, so nothing before 11:00 local.
    $times = localTimes(slotsFor($studio, '2026-06-10'));

    expect($times)->not->toContain('09:00');
    expect($times[0])->toBe('11:00');
});

it('refuses to look further ahead than the booking horizon', function (): void {
    $studio = (new StudioFactory(durationMinutes: 60))->openEveryDay();

    $studio->tenant->update([
        'settings' => ['booking' => ['max_advance_days' => 3, 'min_notice_minutes' => 0]],
    ]);

    expect(slotsFor($studio, '2026-06-30'))->toBe([]);
});

it('honours a rule that is not yet in force', function (): void {
    $studio = new StudioFactory(durationMinutes: 60);

    $studio->withHours([[4, '09:00', '12:00']]);
    $studio->staff->availabilityRules()->update(['effective_from' => '2026-07-01']);

    expect(slotsFor($studio, '2026-06-11'))->toBe([]);
    expect(slotsFor($studio, '2026-07-02'))->not->toBe([]);
});

describe('timezones', function (): void {
    it('renders the same instant in the caller\'s zone', function (): void {
        $studio = (new StudioFactory(durationMinutes: 60))->withHours([[4, '09:00', '11:00']]);

        $vienna = slotsFor($studio, '2026-06-11', tz: 'Europe/Vienna');
        $dhaka = slotsFor($studio, '2026-06-11', tz: 'Asia/Dhaka');

        // Same underlying instants…
        expect($vienna[0]->startsAt->equalTo($dhaka[0]->startsAt))->toBeTrue();

        // …different wall clocks. Vienna is UTC+2 in June, Dhaka UTC+6.
        expect($vienna[0]->localStart()->format('H:i'))->toBe('09:00');
        expect($dhaka[0]->localStart()->format('H:i'))->toBe('13:00');
    });

    it('interprets a staff member\'s hours in their own zone', function (): void {
        // The business is in Vienna; this consultant works 09:00–13:00 Kolkata,
        // which is 05:30–09:30 Vienna in June.
        $studio = new StudioFactory(durationMinutes: 60);
        $studio->staff->update(['timezone' => 'Asia/Kolkata']);
        $studio->withHours([[4, '09:00', '13:00']]);

        $times = localTimes(slotsFor($studio, '2026-06-11', tz: 'Europe/Vienna'));

        expect($times[0])->toBe('05:30');
        expect(end($times))->toBe('08:30');
    });
});

describe('daylight saving', function (): void {
    /*
     * Both of these compare the transition day against an ordinary Sunday
     * rather than asserting a hard-coded count. That keeps the test about the
     * thing it is testing — the window really is an hour shorter, and really
     * is an hour longer — instead of about the slot grid, which is configured
     * elsewhere and may change.
     *
     * One hour on the 15 minute grid these studios use is four slots.
     */

    it('loses an hour on the day the clocks go forward', function (): void {
        // 29 March 2026, Europe/Vienna: 02:00 jumps to 03:00, so a 01:00–05:00
        // shift is three real hours.
        $studio = new StudioFactory(durationMinutes: 60);
        $studio->withHours([[0, '01:00', '05:00']]);   // Sundays

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 00:00', 'UTC'));

        $ordinary = count(slotsFor($studio, '2026-03-22'));
        $transition = count(slotsFor($studio, '2026-03-29'));

        expect($ordinary)->toBeGreaterThan(0);
        expect($transition)->toBe($ordinary - 4);
    });

    it('gains an hour on the day the clocks go back', function (): void {
        // 25 October 2026: 03:00 falls back to 02:00, so the shift is five hours.
        $studio = new StudioFactory(durationMinutes: 60);
        $studio->withHours([[0, '01:00', '05:00']]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 00:00', 'UTC'));

        $ordinary = count(slotsFor($studio, '2026-10-18'));
        $transition = count(slotsFor($studio, '2026-10-25'));

        expect($ordinary)->toBeGreaterThan(0);
        expect($transition)->toBe($ordinary + 4);
    });

    it('keeps a slot on the correct side of the transition', function (): void {
        // A 09:00 Vienna appointment is 07:00 UTC in winter and 06:00 UTC in
        // summer. Getting this wrong is the classic booking-system bug.
        $studio = new StudioFactory(durationMinutes: 60);
        $studio->withHours([[0, '09:00', '11:00']]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 00:00', 'UTC'));

        $winter = slotsFor($studio, '2026-03-22');
        $summer = slotsFor($studio, '2026-04-05');

        expect($winter[0]->startsAt->format('H:i'))->toBe('08:00');   // UTC
        expect($summer[0]->startsAt->format('H:i'))->toBe('07:00');   // UTC
        expect($winter[0]->localStart()->format('H:i'))->toBe('09:00');
        expect($summer[0]->localStart()->format('H:i'))->toBe('09:00');
    });
});

it('aligns the grid to the local clock after an awkward gap', function (): void {
    // A booking ending at 10:07 must not produce 10:07, 10:22, 10:37 …
    $studio = (new StudioFactory(durationMinutes: 30))->withHours([[4, '09:00', '12:00']]);

    Booking::factory()
        ->startingAt(CarbonImmutable::parse('2026-06-11 09:00', 'Europe/Vienna'), 67)
        ->create([
            'tenant_id' => $studio->tenant->id,
            'service_id' => $studio->service->id,
            'staff_id' => $studio->staff->id,
        ]);

    $times = localTimes(slotsFor($studio, '2026-06-11'));

    expect($times[0])->toBe('10:15');
    foreach ($times as $time) {
        expect((int) substr($time, 3) % 15)->toBe(0);
    }
});

it('merges the diaries of everyone who performs the service', function (): void {
    $studio = (new StudioFactory(durationMinutes: 120))->withHours([[4, '09:00', '11:00']]);
    $colleague = $studio->addColleague();

    $colleague->availabilityRules()->create([
        'tenant_id' => $studio->tenant->id,
        'weekday' => 4,
        'starts_at' => '14:00',
        'ends_at' => '16:00',
    ]);

    $slots = slotsFor($studio, '2026-06-11');

    expect($slots)->toHaveCount(2);
    expect(localTimes($slots))->toBe(['09:00', '14:00']);
});
