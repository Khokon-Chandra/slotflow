<?php

declare(strict_types=1);

namespace App\Ai\Results;

use Carbon\CarbonImmutable;

/**
 * What the customer appears to want, extracted from free text.
 *
 * Note what this object is *not*: a booking. It is a search narrowing — a
 * service, a date window and a part of the day. The slots the customer is then
 * offered come from the availability engine, and the booking itself goes
 * through BookingService with the same validation as any other. The model
 * moves the cursor; it never signs the appointment.
 */
final readonly class BookingIntent
{
    /**
     * @param  'high'|'medium'|'low'  $confidence
     * @param  'morning'|'afternoon'|'evening'|'any'  $timeOfDay
     */
    public function __construct(
        public ?int $serviceId,
        public string $confidence,
        public ?int $staffId,
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateUntil,
        public string $timeOfDay,
        public ?string $earliestTime,
        public ?string $latestTime,
        public string $summary,
        public ?string $clarification,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $timezone, CarbonImmutable $today): self
    {
        $from = self::parseDate($data['date_from'] ?? null, $timezone) ?? $today;
        $until = self::parseDate($data['date_until'] ?? null, $timezone) ?? $from;

        // A model — or a heuristic — can hand back a reversed or absurd range.
        // Clamp it here rather than let it reach the availability engine,
        // which would (correctly) reject it with a 422 the customer cannot act on.
        if ($until->lessThan($from)) {
            $until = $from;
        }

        if ($from->diffInDays($until) > 30) {
            $until = $from->addDays(30);
        }

        $timeOfDay = in_array($data['time_of_day'] ?? 'any', ['morning', 'afternoon', 'evening', 'any'], true)
            ? $data['time_of_day']
            : 'any';

        $confidence = in_array($data['confidence'] ?? 'low', ['high', 'medium', 'low'], true)
            ? $data['confidence']
            : 'low';

        return new self(
            serviceId: isset($data['service_id']) ? (int) $data['service_id'] : null,
            confidence: $confidence,
            staffId: isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            dateFrom: $from,
            dateUntil: $until,
            timeOfDay: $timeOfDay,
            earliestTime: self::parseClock($data['earliest_time'] ?? null),
            latestTime: self::parseClock($data['latest_time'] ?? null),
            summary: (string) ($data['summary'] ?? 'Looking for an appointment.'),
            clarification: filled($data['clarification'] ?? null) ? (string) $data['clarification'] : null,
        );
    }

    public function needsService(): bool
    {
        return $this->serviceId === null;
    }

    /**
     * Does a slot at this local time match the requested part of the day?
     */
    public function matchesTime(CarbonImmutable $localStart): bool
    {
        if ($this->earliestTime !== null && $localStart->format('H:i') < $this->earliestTime) {
            return false;
        }

        if ($this->latestTime !== null && $localStart->format('H:i') > $this->latestTime) {
            return false;
        }

        return match ($this->timeOfDay) {
            'morning' => $localStart->hour < 12,
            'afternoon' => $localStart->hour >= 12 && $localStart->hour < 17,
            'evening' => $localStart->hour >= 17,
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'confidence' => $this->confidence,
            'staff_id' => $this->staffId,
            'date_from' => $this->dateFrom->toDateString(),
            'date_until' => $this->dateUntil->toDateString(),
            'time_of_day' => $this->timeOfDay,
            'earliest_time' => $this->earliestTime,
            'latest_time' => $this->latestTime,
            'summary' => $this->summary,
            'clarification' => $this->clarification,
        ];
    }

    private static function parseDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($value, $timezone)->startOfDay();
    }

    private static function parseClock(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1
            ? $value
            : null;
    }
}
