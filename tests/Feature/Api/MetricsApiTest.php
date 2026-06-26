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
