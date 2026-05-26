<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'timezone' => 'Europe/Vienna',
            'completed_count' => 0,
            'no_show_count' => 0,
            'cancelled_count' => 0,
        ];
    }

    /** A reliable regular — the protective end of the risk model. */
    public function loyal(): self
    {
        return $this->state(fn () => [
            'completed_count' => fake()->numberBetween(4, 20),
            'no_show_count' => 0,
        ]);
    }

    /** Someone with a track record of not turning up. */
    public function unreliable(): self
    {
        return $this->state(fn () => [
            'completed_count' => fake()->numberBetween(1, 3),
            'no_show_count' => fake()->numberBetween(2, 4),
        ]);
    }

    public function unreachable(): self
    {
        return $this->state(fn () => ['phone' => null]);
    }
}
