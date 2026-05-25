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
