<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
final class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->addDays(fake()->numberBetween(1, 14))->setTime(10, 0)->utc();
        $duration = 45;

        return [
            'reference' => 'DF-'.strtoupper(Str::random(6)),
            'service_id' => Service::factory(),
            'staff_id' => Staff::factory(),
            'customer_id' => Customer::factory(),
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($duration),
            'blocks_until' => $start->addMinutes($duration),
            'status' => BookingStatus::Confirmed,
            'source' => BookingSource::Web,
            'customer_timezone' => 'Europe/Vienna',
            'price_cents' => 5000,
            'confirmed_at' => CarbonImmutable::now(),
        ];
    }

    public function startingAt(CarbonImmutable $start, int $durationMinutes = 45, int $bufferMinutes = 0): self
    {
        return $this->state(fn () => [
            'starts_at' => $start->utc(),
            'ends_at' => $start->utc()->addMinutes($durationMinutes),
            'blocks_until' => $start->utc()->addMinutes($durationMinutes + $bufferMinutes),
        ]);
    }

    public function status(BookingStatus $status): self
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
