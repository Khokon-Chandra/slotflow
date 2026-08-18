<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Support\StudioFactory;

/**
 * Session authentication for the browser-facing pages.
 *
 * Signing out is covered per role on purpose. The customer case is the one
 * that was broken: the header branched admin-or-guest, and a signed-in
 * customer is neither, so the button was never rendered and there was no way
 * out of the session. The backend route worked the whole time — nothing
 * exercised it as a customer.
 */
beforeEach(function (): void {
    $this->studio = new StudioFactory;

    // A guest hitting /login has no token and no header, so ResolveDemoTenant
    // falls back to this deployment's default workspace. Pointing it at the
    // studio under test is what the seeded demo does in production.
    config()->set('slotflow.demo.tenant_slug', $this->studio->tenant->slug);
});

it('signs a customer in and sends them to the booking page', function (): void {
    $customer = $this->studio->customerUser();

    $this->post('/login', ['email' => $customer->email, 'password' => 'password'])
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($customer);
});

it('sends the business to the admin panel instead', function (): void {
    $owner = $this->studio->owner();

    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard'));
});

it('lets every role sign out', function (UserRole $role): void {
    $user = User::factory()->create([
        'tenant_id' => $this->studio->tenant->id,
        'role' => $role,
    ]);

    $this->actingAs($user);
    expect(Auth::check())->toBeTrue();

    $this->post('/logout')->assertRedirect(route('home'));

    $this->assertGuest();
})->with([
    'customer' => UserRole::Customer,
    'staff' => UserRole::Staff,
    'owner' => UserRole::Owner,
]);

it('invalidates the session on the way out', function (): void {
    $customer = $this->studio->customerUser();

    $this->actingAs($customer);
    $this->post('/logout');

    // The session is gone, not merely logged out — a protected page must
    // bounce rather than serve a cached identity.
    $this->get('/admin')->assertRedirect(route('login'));
});

it('will not sign out someone who is not signed in', function (): void {
    $this->post('/logout')->assertRedirect(route('login'));
});

it('scopes login to the workspace', function (): void {
    // The same address may exist in two workspaces. Signing in to the wrong
    // one is not a helpful outcome.
    $other = new StudioFactory;
    $stranger = User::factory()->create([
        'tenant_id' => $other->tenant->id,
        'email' => 'shared@example.test',
    ]);

    // The demo tenant is resolved for guests, so this attempts to sign a
    // member of the *other* workspace into it.
    $this->post('/login', ['email' => $stranger->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
