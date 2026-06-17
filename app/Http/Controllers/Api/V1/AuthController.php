<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\Customer;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Authentication
 *
 * Token authentication for API clients (Laravel Sanctum). Send the token as
 * `Authorization: Bearer <token>` on every subsequent request.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Register a customer
     *
     * Creates a customer account in the workspace named by the `X-Tenant`
     * header, and returns a token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $tenant = $this->tenants->require();

        $user = DB::transaction(function () use ($request, $tenant): User {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'role' => UserRole::Customer,
                'phone' => $request->input('phone'),
                'timezone' => $request->string('timezone')->toString(),
            ]);

            // Link any bookings this person already made as a guest, so their
            // history — and therefore their risk profile — survives signing up.
            $customer = Customer::query()->where('email', $user->email)->first();

            if ($customer !== null) {
                $customer->update(['user_id' => $user->id]);
            } else {
                Customer::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'timezone' => $user->timezone,
                ]);
            }

            return $user;
        });

        return response()->json([
            'data' => [
                'token' => $user->createToken('registration')->plainTextToken,
                'user' => $this->userPayload($user),
            ],
        ], 201);
    }

    /**
     * Log in
     *
     * Exchanges credentials for a bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();

        // Throttle per email *and* per IP. Per-IP alone lets a botnet spray
        // one account; per-email alone lets one host enumerate the whole
        // customer list.
        $throttleKey = 'login:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ])->status(429);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);

            // One message for both cases: saying "no such account" tells an
            // attacker which addresses are worth guessing passwords for.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        return response()->json([
            'data' => [
                'token' => $user->createToken($request->string('device_name')->toString())->plainTextToken,
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    /**
     * The current user
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    /**
     * Log out
     *
     * Revokes only the token used for this request; other devices stay signed in.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Token revoked.']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'timezone' => $user->timezone,
            'tenant' => [
                'name' => $user->tenantModel()->name,
                'slug' => $user->tenantModel()->slug,
                'timezone' => $user->tenantModel()->timezone,
                'currency' => $user->tenantModel()->currency,
            ],
        ];
    }
}
