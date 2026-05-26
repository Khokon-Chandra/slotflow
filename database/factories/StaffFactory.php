<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
final class StaffFactory extends Factory
{
    protected $model = Staff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'name' => fake()->firstName().' '.fake()->lastName(),
            'title' => fake()->jobTitle(),
            'bio' => fake()->sentence(16),
            'timezone' => 'Europe/Vienna',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inTimezone(string $timezone): self
    {
        return $this->state(fn () => ['timezone' => $timezone]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
