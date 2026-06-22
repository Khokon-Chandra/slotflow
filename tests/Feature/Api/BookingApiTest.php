<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->studio = (new StudioFactory(durationMinutes: 60))->openEveryDay('09:00', '17:00');
    $this->tenantHeader = ['X-Tenant' => $this->studio->tenant->slug];

    $this->payload = [
        'service_id' => $this->studio->service->id,
        'staff_id' => $this->studio->staff->id,
        'starts_at' => CarbonImmutable::parse('2026-06-11 09:00', 'Europe/Vienna')->toIso8601String(),
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.test',
        'customer_phone' => '+43 660 1234567',
        'customer_timezone' => 'Europe/Vienna',
    ];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('creates a booking without an account', function (): void {
    // Making a customer register before they can make an appointment is the
    // easiest way to lose the appointment.
    $response = $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.timezone', 'Europe/Vienna')
        ->assertJsonPath('data.local_starts_at', '2026-06-11T09:00:00+02:00')
        ->assertJsonPath('data.starts_at', '2026-06-11T07:00:00+00:00');

    expect($response->json('data.reference'))->toMatch('/^[A-Z]{2}-[A-Z0-9]{6}$/');
});

it('stores the instant in UTC however the client expressed it', function (): void {
    // "+02:00" and "Z" describe the same moment and must be stored identically.
    $utcForm = [...$this->payload, 'starts_at' => '2026-06-11T07:00:00Z'];

    $this->postJson('/api/v1/bookings', $utcForm, $this->tenantHeader)->assertCreated();

    $booking = Booking::query()->withoutTenantScope()->sole();

    expect($booking->starts_at->toIso8601String())->toBe('2026-06-11T07:00:00+00:00');
});

it('returns 409 with a machine-readable code when the slot has gone', function (): void {
    $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)->assertCreated();

    $response = $this->postJson(
        '/api/v1/bookings',
        [...$this->payload, 'customer_email' => 'other@example.test'],
        $this->tenantHeader,
    );

    $response->assertStatus(409);
    expect($response)->toHaveErrorCode('slot_unavailable');
    $response->assertJsonPath('error.context.staff_id', $this->studio->staff->id);
});

it('refuses a time outside working hours', function (): void {
    $response = $this->postJson('/api/v1/bookings', [
        ...$this->payload,
        'starts_at' => CarbonImmutable::parse('2026-06-11 22:00', 'Europe/Vienna')->toIso8601String(),
    ], $this->tenantHeader);

    $response->assertStatus(409);
    expect($response)->toHaveErrorCode('slot_unavailable');
});
