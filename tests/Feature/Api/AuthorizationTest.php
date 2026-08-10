<?php

declare(strict_types=1);

use App\Models\Service;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

/**
 * Who may do what.
 *
 * Every write endpoint is checked from three angles — anonymous, a customer,
 * and a staff member — because the interesting failures are the ones where a
 * signed-in user of the *right* tenant does something they should not.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->studio = (new StudioFactory)->openEveryDay();
    $this->headers = ['X-Tenant' => $this->studio->tenant->slug];
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lets anyone read the service list', function (): void {
    $this->getJson('/api/v1/services', $this->headers)->assertOk();
    $this->getJson('/api/v1/staff', $this->headers)->assertOk();
});

it('requires a token to create a service', function (): void {
    $response = $this->postJson('/api/v1/services', ['name' => 'X'], $this->headers);

    $response->assertUnauthorized();
    expect($response)->toHaveErrorCode('unauthenticated');
});

it('refuses a customer', function (): void {
    Sanctum::actingAs($this->studio->customerUser());

    $response = $this->postJson('/api/v1/services', [
        'name' => 'Sneaky service',
        'duration_minutes' => 30,
        'price_cents' => 100,
    ]);

    $response->assertForbidden();
    expect($response)->toHaveErrorCode('forbidden');
});

it('refuses a staff member: services belong to the owner', function (): void {
    Sanctum::actingAs($this->studio->staffUser());

    $this->postJson('/api/v1/services', [
        'name' => 'Not mine',
        'duration_minutes' => 30,
        'price_cents' => 100,
    ])->assertForbidden();
});

it('lets the owner create a service', function (): void {
    Sanctum::actingAs($this->studio->owner());

    $this->postJson('/api/v1/services', [
        'name' => 'Deep conditioning',
        'duration_minutes' => 30,
        'price_cents' => 3200,
        'staff_ids' => [$this->studio->staff->id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'deep-conditioning')
        ->assertJsonCount(1, 'data.staff');
});

it('lets a staff member manage their own hours but not a colleague\'s', function (): void {
    $colleague = $this->studio->addColleague();

    Sanctum::actingAs($this->studio->staffUser());

    $payload = ['rules' => [['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '16:00']]];

    $this->putJson("/api/v1/staff/{$this->studio->staff->id}/availability-rules", $payload)->assertOk();
    $this->putJson("/api/v1/staff/{$colleague->id}/availability-rules", $payload)->assertForbidden();
});

it('refuses to delete a service with bookings ahead of it', function (): void {
    Sanctum::actingAs($this->studio->owner());

    App\Models\Booking::factory()
        ->startingAt(CarbonImmutable::parse('2026-06-20 10:00', 'Europe/Vienna'), 60)
        ->create([
            'tenant_id' => $this->studio->tenant->id,
            'service_id' => $this->studio->service->id,
            'staff_id' => $this->studio->staff->id,
        ]);

    $response = $this->deleteJson("/api/v1/services/{$this->studio->service->slug}");

    $response->assertStatus(409);
    expect($response)->toHaveErrorCode('service_has_bookings');
    expect(Service::query()->count())->toBe(1);
});

it('deletes a service nobody is booked into', function (): void {
    Sanctum::actingAs($this->studio->owner());

    $this->deleteJson("/api/v1/services/{$this->studio->service->slug}")->assertNoContent();

    expect(Service::query()->count())->toBe(0);
});

it('keeps the admin diary behind the admin gate', function (): void {
    Sanctum::actingAs($this->studio->customerUser());

    $this->getJson('/api/v1/admin/bookings')->assertForbidden();
    $this->getJson('/api/v1/admin/metrics')->assertForbidden();
    $this->getJson('/api/v1/ai/daily-briefing')->assertForbidden();
});

it('opens the admin diary to staff and owners', function (): void {
    Sanctum::actingAs($this->studio->staffUser());
    $this->getJson('/api/v1/admin/bookings')->assertOk();

    Sanctum::actingAs($this->studio->owner());
    $this->getJson('/api/v1/admin/metrics')->assertOk();
});
