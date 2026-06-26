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
