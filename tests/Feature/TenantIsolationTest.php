<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

/**
 * Multi-tenancy.
 *
 * The failure this guards against is not a crash. It is one business quietly
 * reading another's customer list, which nothing in the UI would reveal and no
 * ordinary test would notice — so it gets its own file.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->salon = (new StudioFactory)->openEveryDay();
    $this->salon->service->update(['name' => 'Salon service']);
    $this->salonOwner = $this->salon->owner();

    // A second, unrelated business.
    app(TenantContext::class)->forget();
    $this->clinic = (new StudioFactory)->openEveryDay();
    $this->clinic->service->update(['name' => 'Clinic service']);
    $this->clinicOwner = $this->clinic->owner();

    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('hides other tenants\' records behind the global scope', function (): void {
    app(TenantContext::class)->set($this->salon->tenant);

    expect(Service::query()->pluck('name')->all())->toBe(['Salon service']);
    expect(Staff::query()->count())->toBe(1);
});

it('fills tenant_id automatically on create', function (): void {
    app(TenantContext::class)->set($this->clinic->tenant);

    $service = Service::create([
        'name' => 'Follow-up',
        'slug' => 'follow-up',
        'duration_minutes' => 30,
        'price_cents' => 1000,
    ]);

    expect($service->tenant_id)->toBe($this->clinic->tenant->id);
});

it('does not leak across tenants through the API', function (): void {
    Sanctum::actingAs($this->salonOwner);

    $this->getJson('/api/v1/services')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Salon service');
});

it('refuses a token whose tenant does not match the requested workspace', function (): void {
    Sanctum::actingAs($this->salonOwner);

    // Authenticated as the salon, asking for the clinic's workspace. Not a
    // routing quirk — someone probing. Refused rather than silently corrected.
    $this->getJson('/api/v1/services', ['X-Tenant' => $this->clinic->tenant->slug])
        ->assertForbidden();
});

it('returns 404 rather than 403 for another tenant\'s record', function (): void {
    app(TenantContext::class)->set($this->clinic->tenant);

    $clinicBooking = Booking::factory()
        ->startingAt(CarbonImmutable::parse('2026-06-15 10:00', 'Europe/Vienna'), 60)
        ->create([
            'tenant_id' => $this->clinic->tenant->id,
            'service_id' => $this->clinic->service->id,
            'staff_id' => $this->clinic->staff->id,
        ]);

    app(TenantContext::class)->forget();
    Sanctum::actingAs($this->salonOwner);

    // 404, not 403: confirming that a reference exists somewhere on the
    // platform is itself a small leak.
    $response = $this->getJson("/api/v1/bookings/{$clinicBooking->reference}");

    $response->assertNotFound();
    expect($response)->toHaveErrorCode('not_found');
});

it('scopes availability to the requesting tenant\'s services', function (): void {
    // The clinic's service id, asked for with the salon's workspace header.
    // It fails validation rather than 404-ing at the lookup, because the
    // `exists` rule is tenant-scoped — see ScopesExistenceToTenant.
    $response = $this->getJson(
        "/api/v1/availability?service_id={$this->clinic->service->id}&date=2026-06-15&tz=Europe/Vienna",
        ['X-Tenant' => $this->salon->tenant->slug],
    );

    $response->assertStatus(422);
    expect($response)->toHaveErrorCode('validation_failed');
    $response->assertJsonPath('error.fields.service_id.0', fn (string $m) => str_contains($m, 'service id'));
});

it('keeps the same email separate in two workspaces', function (): void {
    // One person, two businesses, two accounts. Email is unique per tenant,
    // not globally.
    app(TenantContext::class)->set($this->salon->tenant);
    App\Models\User::factory()->create(['tenant_id' => $this->salon->tenant->id, 'email' => 'shared@example.test']);

    app(TenantContext::class)->set($this->clinic->tenant);
    $second = App\Models\User::factory()->create(['tenant_id' => $this->clinic->tenant->id, 'email' => 'shared@example.test']);

    expect($second->exists)->toBeTrue();
    expect(App\Models\User::query()->withoutTenantScope()->where('email', 'shared@example.test')->count())->toBe(2);
});

it('exposes an explicit escape hatch rather than a silent one', function (): void {
    app(TenantContext::class)->set($this->salon->tenant);

    expect(Service::query()->count())->toBe(1);
    expect(Service::query()->withoutTenantScope()->count())->toBe(2);
});
