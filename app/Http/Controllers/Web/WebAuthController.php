<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session login for the admin panel. API clients use tokens instead
 * (Api\V1\AuthController).
 */
final class WebAuthController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function show(): Response
    {
        return Inertia::render('Auth/Login', [
            'demo' => [
                'owner' => config('slotflow.demo.owner_email'),
                'staff' => config('slotflow.demo.staff_email'),
                'customer' => config('slotflow.demo.customer_email'),
                'password' => config('slotflow.demo.password'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $this->tenants->require();

        // Scoped to the tenant: the same address may exist in two workspaces,
        // and signing in to the wrong one is not a helpful outcome.
        $ok = Auth::attempt([
            ...$credentials,
            'tenant_id' => $tenant->id,
        ], remember: $request->boolean('remember'));

        if (! $ok) {
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(
            $request->user()->canAccessAdminArea() ? route('admin.dashboard') : route('home')
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
