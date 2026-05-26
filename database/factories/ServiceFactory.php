<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
final class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Consultation', 'Follow-up', 'Assessment', 'Treatment', 'Review',
        ]).' '.fake()->numberBetween(1, 999);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(14),
            'keywords' => null,
            'duration_minutes' => fake()->randomElement([30, 45, 60]),
            'buffer_minutes' => fake()->randomElement([0, 10, 15]),
            'price_cents' => fake()->numberBetween(2000, 15000),
            'color' => fake()->hexColor(),
            'is_active' => true,
            'requires_deposit' => false,
            'deposit_cents' => 0,
            'sort_order' => 0,
        ];
    }

    public function lasting(int $minutes, int $buffer = 0): self
    {
        return $this->state(fn () => [
            'duration_minutes' => $minutes,
            'buffer_minutes' => $buffer,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withDeposit(int $cents = 2000): self
    {
        return $this->state(fn () => [
            'requires_deposit' => true,
            'deposit_cents' => $cents,
        ]);
    }
}
