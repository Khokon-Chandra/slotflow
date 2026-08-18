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

it('revokes only the token that was used', function (): void {
    // Two real tokens rather than Sanctum::actingAs, which fakes a transient
    // one and would let this pass without anything actually being revoked.
    $user = $this->studio->customerUser();
    $user->update(['password' => 'correct-horse-battery']);

    $login = fn (string $device): string => $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
        'device_name' => $device,
    ], $this->headers)->json('data.token');

    $phone = $login('iPhone');
    RateLimiter::clear('login:'.sha1($user->email.'|127.0.0.1'));
    $laptop = $login('Laptop');

    expect($user->tokens()->count())->toBe(2);

    $this->withToken($phone)->postJson('/api/v1/auth/logout', [], $this->headers)->assertOk();

    // Only the phone's token is gone.
    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->sole()->name)->toBe('Laptop');

    // The guard caches whichever user it resolved for the previous request,
    // and the whole application instance is reused between requests in a test.
    // A genuine second HTTP request would resolve from scratch, so forget the
    // guards to model that — otherwise this asserts the cache, not the token.
    $this->app['auth']->forgetGuards();
    $this->withToken($phone)->getJson('/api/v1/auth/me', $this->headers)->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withToken($laptop)->getJson('/api/v1/auth/me', $this->headers)->assertOk();
});

it('lets a customer sign out of the API', function (): void {
    // The web header had no sign-out for customers for a while. The API never
    // had that gap, and this is what keeps it that way.
    Sanctum::actingAs($this->studio->customerUser());

    $this->getJson('/api/v1/auth/me')->assertOk();
    $this->postJson('/api/v1/auth/logout')->assertOk();
});

it('returns the current user', function (): void {
    Sanctum::actingAs($this->studio->owner());

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.role', UserRole::Owner->value);
});

it('rejects a request with no token', function (): void {
    $response = $this->getJson('/api/v1/auth/me', $this->headers);

    $response->assertUnauthorized();
    expect($response)->toHaveErrorCode('unauthenticated');
});
