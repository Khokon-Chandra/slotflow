<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->studio = (new StudioFactory)->openEveryDay();
    Sanctum::actingAs($this->studio->owner());
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('returns zeroes for a business with no history', function (): void {
    // The empty case is the one that breaks: every status bucket is missing,
    // and code that reaches into the result by key falls over.
    $this->getJson('/api/v1/admin/metrics')
        ->assertOk()
        ->assertJsonPath('data.totals.bookings', 0)
        ->assertJsonPath('data.totals.completed', 0)
        ->assertJsonPath('data.totals.no_shows', 0)
        ->assertJsonPath('data.no_show_rate', 0)
        ->assertJsonPath('data.revenue.realised_cents', 0)
        ->assertJsonPath('data.revenue.lost_to_no_shows_cents', 0);
});

it('survives a diary containing only one status', function (): void {
    Booking::factory()
        ->startingAt(CarbonImmutable::parse('2026-06-12 10:00', 'Europe/Vienna'), 60)
        ->create([
            'tenant_id' => $this->studio->tenant->id,
            'service_id' => $this->studio->service->id,
            'staff_id' => $this->studio->staff->id,
        ]);

    $this->getJson('/api/v1/admin/metrics')
        ->assertOk()
        ->assertJsonPath('data.totals.bookings', 1)
        ->assertJsonPath('data.totals.completed', 0);
});

it('computes the no-show rate over resolved appointments only', function (): void {
    $make = function (BookingStatus $status, string $at, int $price): void {
        Booking::factory()
            ->startingAt(CarbonImmutable::parse($at, 'Europe/Vienna'), 60)
            ->status($status)
            ->create([
                'tenant_id' => $this->studio->tenant->id,
                'service_id' => $this->studio->service->id,
                'staff_id' => $this->studio->staff->id,
                'price_cents' => $price,
            ]);
    };

    $make(BookingStatus::Completed, '2026-06-01 10:00', 5000);
    $make(BookingStatus::Completed, '2026-06-02 10:00', 5000);
    $make(BookingStatus::Completed, '2026-06-03 10:00', 5000);
    $make(BookingStatus::NoShow, '2026-06-04 10:00', 5000);
    // A cancellation is not a no-show and must not be in the denominator.
    $make(BookingStatus::Cancelled, '2026-06-05 10:00', 5000);

    $this->getJson('/api/v1/admin/metrics')
        ->assertOk()
        ->assertJsonPath('data.no_show_rate', 0.25)             // 1 of 4 resolved
        ->assertJsonPath('data.revenue.realised_cents', 15000)
        ->assertJsonPath('data.revenue.lost_to_no_shows_cents', 5000);
});

it('reports today alongside the rolling window', function (): void {
    $this->getJson('/api/v1/admin/metrics')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['window_days', 'totals', 'revenue', 'no_show_rate', 'today' => ['date', 'booking_count']],
        ]);
});
