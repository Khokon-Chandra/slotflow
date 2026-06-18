<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->studio = new StudioFactory;
    $this->headers = ['X-Tenant' => $this->studio->tenant->slug];

    RateLimiter::clear('login:'.sha1('ada@example.test|127.0.0.1'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('registers a customer and returns a token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'timezone' => 'Europe/Vienna',
    ], $this->headers);

    $response->assertCreated()
        ->assertJsonPath('data.user.role', 'customer')
        ->assertJsonPath('data.user.tenant.slug', $this->studio->tenant->slug);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('adopts a guest\'s booking history on registration', function (): void {
    // Someone who booked as a guest and signs up later keeps their history —
    // and therefore their risk profile.
    Customer::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'email' => 'ada@example.test',
        'completed_count' => 3,
    ]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'timezone' => 'Europe/Vienna',
    ], $this->headers)->assertCreated();

    $customer = Customer::query()->where('email', 'ada@example.test')->sole();

    expect($customer->completed_count)->toBe(3);
    expect($customer->user_id)->not->toBeNull();
    expect(Customer::query()->count())->toBe(1);
});

it('exchanges credentials for a token', function (): void {
    User::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'iPhone',
    ], $this->headers)
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

it('gives the same answer for a wrong password and an unknown address', function (): void {
    User::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.test',
        'password' => 'nope',
        'device_name' => 'iPhone',
    ], $this->headers);

    RateLimiter::clear('login:'.sha1('ada@example.test|127.0.0.1'));

    $unknownUser = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.test',
        'password' => 'nope',
        'device_name' => 'iPhone',
    ], $this->headers);

    // Distinguishing the two tells an attacker which addresses are worth
    // spending guesses on.
    expect($wrongPassword->status())->toBe($unknownUser->status());
    expect($wrongPassword->json('error.fields.email.0'))
        ->toBe($unknownUser->json('error.fields.email.0'));
});

it('throttles repeated failures', function (): void {
    User::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
    ]);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.test',
            'password' => 'wrong',
            'device_name' => 'iPhone',
        ], $this->headers)->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'iPhone',
    ], $this->headers)->assertStatus(429);
});
