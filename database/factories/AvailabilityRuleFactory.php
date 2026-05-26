<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AvailabilityRule;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityRule>
 */
final class AvailabilityRuleFactory extends Factory
{
    protected $model = AvailabilityRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'weekday' => fake()->numberBetween(1, 5),
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'effective_from' => null,
            'effective_until' => null,
        ];
    }

    public function on(int $weekday, string $startsAt = '09:00', string $endsAt = '17:00'): self
    {
        return $this->state(fn () => [
            'weekday' => $weekday,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
