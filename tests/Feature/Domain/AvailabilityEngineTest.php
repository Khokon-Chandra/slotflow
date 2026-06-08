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
