<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A half-open interval on the timeline: [start, end).
 *
 * Half-open is the whole trick. With closed intervals, 09:00–10:00 and
 * 10:00–11:00 "overlap" at the shared instant and you end up writing
 * `>=`/`<=` inconsistently in three places until back-to-back appointments
 * mysteriously stop being bookable. Every comparison in this class — and every
 * SQL overlap predicate in the app — uses `start < otherEnd && end > otherStart`.
 *
 * Instances are always UTC. Converting to a display timezone happens at the
 * edge, in the API resource.
 */
final readonly class TimeRange
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException(
                "A time range must end after it starts; got {$start->toIso8601String()} → {$end->toIso8601String()}."
            );
        }
    }

    public static function of(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self($start->utc(), $end->utc());
    }

    public static function fromMinutes(CarbonImmutable $start, int $minutes): self
    {
        return new self($start->utc(), $start->utc()->addMinutes($minutes));
    }

    public function overlaps(self $other): bool
    {
        return $this->start->lessThan($other->end)
            && $this->end->greaterThan($other->start);
    }

    public function contains(self $other): bool
    {
        return $this->start->lessThanOrEqualTo($other->start)
            && $this->end->greaterThanOrEqualTo($other->end);
    }

    public function durationMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    /**
     * Remove another range from this one.
     *
     * Returns 0, 1 or 2 pieces:
     *   no overlap        → [$this]
     *   fully covered     → []
     *   bites off an end  → one piece
     *   punches a hole    → two pieces
     *
     * @return list<self>
     */
    public function subtract(self $other): array
    {
        if (! $this->overlaps($other)) {
            return [$this];
        }

        $pieces = [];

        if ($this->start->lessThan($other->start)) {
            $pieces[] = new self($this->start, $other->start);
        }

        if ($this->end->greaterThan($other->end)) {
            $pieces[] = new self($other->end, $this->end);
        }

        return $pieces;
    }

    /**
     * Subtract every blocker from every range.
     *
     * This is the core of the availability engine:
     *   free = working hours − time off − existing bookings
     *
     * @param  list<self>  $ranges
     * @param  list<self>  $blockers
     * @return list<self>
     */
    public static function subtractAll(array $ranges, array $blockers): array
    {
        foreach ($blockers as $blocker) {
            $next = [];

            foreach ($ranges as $range) {
                foreach ($range->subtract($blocker) as $piece) {
                    $next[] = $piece;
                }
            }

            $ranges = $next;

            if ($ranges === []) {
                break;
            }
        }

        return $ranges;
    }

    /**
     * Sort by start and merge anything that touches or overlaps, so callers
     * never see "09:00–10:00, 10:00–11:00" where they meant "09:00–11:00".
     *
     * @param  list<self>  $ranges
     * @return list<self>
     */
    public static function merge(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        usort($ranges, fn (self $a, self $b) => $a->start <=> $b->start);

        $merged = [array_shift($ranges)];

        foreach ($ranges as $range) {
            $last = $merged[count($merged) - 1];

            if ($range->start->lessThanOrEqualTo($last->end)) {
                $merged[count($merged) - 1] = new self(
                    $last->start,
                    $range->end->greaterThan($last->end) ? $range->end : $last->end,
                );

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }

    public function __toString(): string
    {
        return $this->start->toIso8601String().' → '.$this->end->toIso8601String();
    }
}
