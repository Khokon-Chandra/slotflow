<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'role' => UserRole::Customer,
            'phone' => fake()->phoneNumber(),
            'timezone' => 'Europe/Vienna',
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): self
    {
        return $this->state(fn () => ['role' => UserRole::Owner]);
    }

    public function staff(): self
    {
        return $this->state(fn () => ['role' => UserRole::Staff]);
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
