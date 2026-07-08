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

it('enforces the minimum notice period', function (): void {
    $this->studio->tenant->update(['settings' => ['booking' => ['min_notice_minutes' => 240]]]);

    $response = $this->postJson('/api/v1/bookings', [
        ...$this->payload,
        'starts_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
    ], $this->tenantHeader);

    $response->assertStatus(422);
    expect($response)->toHaveErrorCode('insufficient_notice');
    $response->assertJsonPath('error.context.min_notice_minutes', 240);
});

it('refuses a booking beyond the horizon', function (): void {
    $this->studio->tenant->update(['settings' => ['booking' => ['max_advance_days' => 7, 'min_notice_minutes' => 0]]]);

    $response = $this->postJson('/api/v1/bookings', [
        ...$this->payload,
        'starts_at' => CarbonImmutable::now()->addDays(30)->setTime(10, 0)->toIso8601String(),
    ], $this->tenantHeader);

    $response->assertStatus(422);
    expect($response)->toHaveErrorCode('beyond_booking_horizon');
});

it('refuses a staff member who does not perform the service', function (): void {
    $outsider = App\Models\Staff::factory()->create(['tenant_id' => $this->studio->tenant->id]);

    $response = $this->postJson(
        '/api/v1/bookings',
        [...$this->payload, 'staff_id' => $outsider->id],
        $this->tenantHeader,
    );

    $response->assertStatus(422);
    expect($response)->toHaveErrorCode('staff_service_mismatch');
});

describe('validation', function (): void {
    it('rejects a missing timezone with a usable message', function (): void {
        $payload = $this->payload;
        unset($payload['customer_timezone']);

        $response = $this->postJson('/api/v1/bookings', $payload, $this->tenantHeader);

        $response->assertStatus(422);
        expect($response)->toHaveErrorCode('validation_failed');
        $response->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['customer_timezone']]]);
    });

    it('rejects an offset masquerading as a timezone', function (): void {
        // "+02:00" cannot express daylight saving. Accepting it looks like it
        // works right up until the clocks change.
        $this->postJson('/api/v1/bookings', [...$this->payload, 'customer_timezone' => '+02:00'], $this->tenantHeader)
            ->assertStatus(422)
            ->assertJsonPath('error.fields.customer_timezone.0', fn (string $m) => str_contains($m, 'IANA'));
    });

    it('rejects an abbreviation', function (): void {
        $this->postJson('/api/v1/bookings', [...$this->payload, 'customer_timezone' => 'CEST'], $this->tenantHeader)
            ->assertStatus(422);
    });
});

it('records how the booking was made', function (): void {
    $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)->assertCreated();

    expect(Booking::query()->withoutTenantScope()->sole()->source)->toBe(BookingSource::Web);
});

it('scores the booking for no-show risk as it is created', function (): void {
    $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)->assertCreated();

    $booking = Booking::query()->withoutTenantScope()->with('riskAssessment')->sole();

    expect($booking->riskAssessment)->not->toBeNull();
    expect($booking->riskAssessment->score)->toBeGreaterThanOrEqual(0);
});

it('reuses an existing customer so their history survives', function (): void {
    $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)->assertCreated();

    $this->postJson('/api/v1/bookings', [
        ...$this->payload,
        'starts_at' => CarbonImmutable::parse('2026-06-12 09:00', 'Europe/Vienna')->toIso8601String(),
    ], $this->tenantHeader)->assertCreated();

    expect(App\Models\Customer::query()->withoutTenantScope()->count())->toBe(1);
});

describe('reading a booking', function (): void {
    it('lets a guest read their own booking with the email they used', function (): void {
        $reference = $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)
            ->json('data.reference');

        $this->getJson("/api/v1/bookings/{$reference}?email=ada@example.test", $this->tenantHeader)
            ->assertOk()
            ->assertJsonPath('data.reference', $reference);
    });

    it('will not hand a booking to someone with only the reference', function (): void {
        $reference = $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)
            ->json('data.reference');

        $this->getJson("/api/v1/bookings/{$reference}", $this->tenantHeader)->assertNotFound();
        $this->getJson("/api/v1/bookings/{$reference}?email=guess@example.test", $this->tenantHeader)
            ->assertNotFound();
    });

    it('hides customer details from unauthenticated readers', function (): void {
        $reference = $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)
            ->json('data.reference');

        $this->getJson("/api/v1/bookings/{$reference}?email=ada@example.test", $this->tenantHeader)
            ->assertOk()
            ->assertJsonMissingPath('data.customer')
            ->assertJsonMissingPath('data.risk');
    });

    it('shows customer details and risk to the business', function (): void {
        $reference = $this->postJson('/api/v1/bookings', $this->payload, $this->tenantHeader)
            ->json('data.reference');

        Sanctum::actingAs($this->studio->owner());

        $this->getJson("/api/v1/bookings/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.customer.email', 'ada@example.test')
            ->assertJsonStructure(['data' => ['risk' => ['score', 'band', 'factors', 'generated_by']]]);
    });
});
