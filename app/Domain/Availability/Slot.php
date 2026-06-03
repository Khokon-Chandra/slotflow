<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use Carbon\CarbonImmutable;

/**
 * One bookable start time, for one staff member.
 *
 * Carries both the UTC instant (what the API stores and compares) and the
 * caller's local rendering (what a human reads). Sending only one of the two
 * is how a booking UI ends up an hour out.
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $staffId,
        public string $staffName,
        public string $displayTimezone,
    ) {}

    public function localStart(): CarbonImmutable
    {
        return $this->startsAt->setTimezone($this->displayTimezone);
    }

    public function localEnd(): CarbonImmutable
    {
        return $this->endsAt->setTimezone($this->displayTimezone);
    }

    /**
     * @return array{
     *     starts_at: string, ends_at: string,
     *     local_starts_at: string, local_ends_at: string,
     *     local_date: string, local_time: string,
     *     staff_id: int, staff_name: string, timezone: string
     * }
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'local_starts_at' => $this->localStart()->toIso8601String(),
            'local_ends_at' => $this->localEnd()->toIso8601String(),
            'local_date' => $this->localStart()->toDateString(),
            'local_time' => $this->localStart()->format('H:i'),
            'staff_id' => $this->staffId,
            'staff_name' => $this->staffName,
            'timezone' => $this->displayTimezone,
        ];
    }
}
