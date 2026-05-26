<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Staff;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeOff>
 */
final class TimeOffFactory extends Factory
{
    protected $model = TimeOff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->addDays(fake()->numberBetween(1, 20))->setTime(9, 0);

        return [
            'staff_id' => Staff::factory(),
            'starts_at' => $start,
            'ends_at' => $start->addHours(4),
            'reason' => fake()->randomElement(['Holiday', 'Training', 'Dentist', 'Sick leave']),
        ];
    }

    public function between(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return $this->state(fn () => ['starts_at' => $start, 'ends_at' => $end]);
    }
}
