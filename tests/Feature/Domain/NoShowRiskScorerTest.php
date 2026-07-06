<?php

declare(strict_types=1);

use App\Domain\Risk\NoShowRiskScorer;
use App\Domain\Risk\RiskFactor;
use App\Enums\RiskBand;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Tests\Support\StudioFactory;

/**
 * The no-show scorer.
 *
 * These tests are the argument for computing the score in PHP rather than
 * asking a model for a number: every factor is asserted individually, the same
 * input always gives the same output, and a weight cannot change without a
 * test noticing.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 08:00:00', 'UTC'));

    $this->studio = new StudioFactory(durationMinutes: 60);
    $this->scorer = app(NoShowRiskScorer::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A booking at a chosen local time for a customer with a chosen history.
 */
function bookingFor(StudioFactory $studio, Customer $customer, string $localStart, int $bookedDaysAgo = 3): Booking
{
    $start = CarbonImmutable::parse($localStart, 'Europe/Vienna');

    return Booking::factory()
        ->startingAt($start, 60)
        ->create([
            'tenant_id' => $studio->tenant->id,
            'service_id' => $studio->service->id,
            'staff_id' => $studio->staff->id,
            'customer_id' => $customer->id,
            'customer_timezone' => 'Europe/Vienna',
            'created_at' => CarbonImmutable::now()->subDays($bookedDaysAgo),
        ]);
}

/** @return list<string> */
function factorCodes(App\Domain\Risk\RiskProfile $profile): array
{
    return array_map(fn (RiskFactor $f) => $f->code, $profile->factors);
}

it('is deterministic', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->studio->tenant->id]);
    $booking = bookingFor($this->studio, $customer, '2026-06-15 14:00');

    $first = $this->scorer->score($booking);
    $second = $this->scorer->score($booking);

    expect($first->score)->toBe($second->score);
    expect($first->toArray())->toBe($second->toArray());
});

it('flags a customer with no history', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'completed_count' => 0,
        'no_show_count' => 0,
    ]);

    $profile = $this->scorer->score(bookingFor($this->studio, $customer, '2026-06-15 14:00'));

    expect(factorCodes($profile))->toContain('first_time_customer');
});

it('rewards a reliable regular', function (): void {
    $customer = Customer::factory()->loyal()->create([
        'tenant_id' => $this->studio->tenant->id,
        'completed_count' => 8,
        'no_show_count' => 0,
    ]);

    $profile = $this->scorer->score(bookingFor($this->studio, $customer, '2026-06-15 14:00'));

    expect(factorCodes($profile))->toContain('reliable_regular');
    expect($profile->band)->toBe(RiskBand::Low);
});

it('puts a customer who misses most appointments in the high band', function (): void {
    // The case the whole feature exists for. If this lands in "watch"
    // alongside every first-timer, the score is telling nobody anything.
    $customer = Customer::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'completed_count' => 2,
        'no_show_count' => 4,
        'phone' => null,
    ]);

    $profile = $this->scorer->score(bookingFor($this->studio, $customer, '2026-06-15 09:00', bookedDaysAgo: 25));

    expect($profile->band)->toBe(RiskBand::High);
    expect(factorCodes($profile))->toContain('prior_no_show_rate');
    expect(factorCodes($profile))->toContain('repeat_no_shows');
});

it('separates a bad rate from a habit', function (): void {
    $onceOutOfTwo = Customer::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'completed_count' => 1,
        'no_show_count' => 1,
    ]);

    $twiceOutOfTen = Customer::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'completed_count' => 8,
        'no_show_count' => 2,
    ]);

    $a = $this->scorer->score(bookingFor($this->studio, $onceOutOfTwo, '2026-06-15 14:00'));
    $b = $this->scorer->score(bookingFor($this->studio, $twiceOutOfTen, '2026-06-15 14:00'));

    // One in two is a worse rate; two in ten is still a repeat pattern.
    expect(factorCodes($a))->not->toContain('repeat_no_shows');
    expect(factorCodes($b))->toContain('repeat_no_shows');
});

it('penalises a booking with no way to send a reminder', function (): void {
    $reachable = Customer::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'phone' => '+43 660 1234567',
    ]);

    $unreachable = Customer::factory()->unreachable()->create([
        'tenant_id' => $this->studio->tenant->id,
    ]);

    $withPhone = $this->scorer->score(bookingFor($this->studio, $reachable, '2026-06-15 14:00'));
    $without = $this->scorer->score(bookingFor($this->studio, $unreachable, '2026-06-15 14:00'));

    expect($without->score)->toBeGreaterThan($withPhone->score);
    expect(factorCodes($without))->toContain('no_phone');
});
