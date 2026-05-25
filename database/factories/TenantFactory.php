<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'timezone' => 'Europe/Vienna',
            'currency' => 'EUR',
            'locale' => 'en',
            'contact_email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'description' => fake()->sentence(12),
            'settings' => null,
        ];
    }

    public function inTimezone(string $timezone): self
    {
        return $this->state(fn () => ['timezone' => $timezone]);
    }
}
