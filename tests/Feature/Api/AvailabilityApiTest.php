<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\StudioFactory;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->studio = (new StudioFactory(durationMinutes: 60))->withHours([[4, '09:00', '12:00']]);
    $this->headers = ['X-Tenant' => $this->studio->tenant->slug];
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('returns slots grouped by local date', function (): void {
    $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&date=2026-06-11&tz=Europe/Vienna",
        $this->headers,
    )
        ->assertOk()
        ->assertJsonPath('data.timezone', 'Europe/Vienna')
        ->assertJsonPath('data.days.0.date', '2026-06-11')
        ->assertJsonPath('data.days.0.slots.0.local_time', '09:00')
        ->assertJsonPath('data.days.0.slots.0.starts_at', '2026-06-11T07:00:00+00:00')
        ->assertJsonStructure([
            'data' => ['service', 'timezone', 'from', 'until', 'slot_count', 'days'],
            'meta' => ['cache_ttl_seconds', 'min_notice_minutes', 'max_advance_days'],
        ]);
});

it('requires a timezone and explains why', function (): void {
    $response = $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&date=2026-06-11",
        $this->headers,
    );

    $response->assertStatus(422);
    expect($response)->toHaveErrorCode('validation_failed');
    $response->assertJsonPath('error.fields.tz.0', fn (string $m) => str_contains($m, 'Europe/Vienna'));
});

it('renders the same slots differently for a different caller', function (): void {
    $vienna = $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&date=2026-06-11&tz=Europe/Vienna",
        $this->headers,
    )->json('data.days.0.slots.0');

    $dhaka = $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&date=2026-06-11&tz=Asia/Dhaka",
        $this->headers,
    )->json('data.days.0.slots.0');

    expect($vienna['starts_at'])->toBe($dhaka['starts_at']);       // same instant
    expect($vienna['local_time'])->toBe('09:00');
    expect($dhaka['local_time'])->toBe('13:00');                   // +4 hours
});

it('caps the range it will search', function (): void {
    // A six-month scan is a denial-of-service dressed up as a feature. It is
    // rejected in validation, so the caller gets something they can act on
    // rather than a 500.
    $response = $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&from=2026-06-11&until=2026-12-31&tz=Europe/Vienna",
        $this->headers,
    );

    $response->assertStatus(422);
    expect($response)->toHaveErrorCode('validation_failed');
    $response->assertJsonPath('error.fields.until.0', fn (string $m) => str_contains($m, 'at most'));
});

it('accepts a range inside the cap', function (): void {
    $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&from=2026-06-11&until=2026-06-25&tz=Europe/Vienna",
        $this->headers,
    )->assertOk();
});

it('needs a workspace', function (): void {
    $this->getJson("/api/v1/availability?service_id={$this->studio->service->id}&date=2026-06-11&tz=UTC")
        ->assertStatus(400);
});

it('rejects an unknown workspace', function (): void {
    $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&date=2026-06-11&tz=UTC",
        ['X-Tenant' => 'nope'],
    )->assertNotFound();
});

it('hides an inactive service', function (): void {
    $this->studio->service->update(['is_active' => false]);

    $this->getJson(
        "/api/v1/availability?service_id={$this->studio->service->id}&date=2026-06-11&tz=Europe/Vienna",
        $this->headers,
    )->assertNotFound();
});
