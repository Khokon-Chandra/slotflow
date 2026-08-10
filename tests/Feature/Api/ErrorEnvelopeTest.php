<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

/**
 * The error contract.
 *
 * Out of the box a Laravel API returns three different error shapes: one for
 * validation, one for missing models, one for everything else. Clients then
 * write three parsers and get the third one wrong.
 *
 * Everything here asserts the same envelope:
 *
 *     { "error": { "code": "...", "message": "...", "fields"?: {...} } }
 *
 * `code` is the stable part. These tests are what stops it drifting.
 */
beforeEach(function (): void {
    $this->studio = new StudioFactory;
    $this->headers = ['X-Tenant' => $this->studio->tenant->slug];
});

it('uses one shape for validation failures', function (): void {
    $response = $this->postJson('/api/v1/bookings', [], $this->headers);

    $response->assertStatus(422)->assertJsonStructure(['error' => ['code', 'message', 'fields']]);
    expect($response)->toHaveErrorCode('validation_failed');
});

it('uses the same shape for a missing record', function (): void {
    Sanctum::actingAs($this->studio->owner());

    $response = $this->getJson('/api/v1/services/does-not-exist');

    $response->assertNotFound()->assertJsonStructure(['error' => ['code', 'message']]);
    expect($response)->toHaveErrorCode('not_found');
});

it('never names the model class in a 404', function (): void {
    Sanctum::actingAs($this->studio->owner());

    $body = $this->getJson('/api/v1/services/does-not-exist')->json('error.message');

    // "App\Models\Service not found" tells a caller the shape of the internals
    // for no benefit to them.
    expect($body)->not->toContain('App\\Models');
    expect($body)->not->toContain('Service');
});

it('uses the same shape for authentication and authorisation', function (): void {
    $unauth = $this->getJson('/api/v1/auth/me', $this->headers);
    $unauth->assertUnauthorized()->assertJsonStructure(['error' => ['code', 'message']]);
    expect($unauth)->toHaveErrorCode('unauthenticated');

    Sanctum::actingAs($this->studio->customerUser());
    $forbidden = $this->getJson('/api/v1/admin/metrics');
    $forbidden->assertForbidden()->assertJsonStructure(['error' => ['code', 'message']]);
    expect($forbidden)->toHaveErrorCode('forbidden');
});

it('uses the same shape for a missing workspace', function (): void {
    $response = $this->getJson('/api/v1/services');

    $response->assertStatus(400);
    expect($response)->toHaveErrorCode('bad_request');
});

it('leaves browser requests as HTML', function (): void {
    // The envelope is for API clients. The Inertia admin panel wants a page.
    $this->get('/nope')->assertNotFound()->assertHeader('content-type', 'text/html; charset=UTF-8');
});
