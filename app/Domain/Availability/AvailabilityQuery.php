<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Models\Service;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The question asked of the availability engine.
 *
 * The timezone is an explicit, required input — never sniffed from a header
 * and never assumed to be the server's. A customer in Dhaka booking an
 * appointment in Vienna is the normal case, not the edge case.
 */
final readonly class AvailabilityQuery
{
    public function __construct(
        public Service $service,
        public CarbonImmutable $fromDate,
        public CarbonImmutable $untilDate,
        public string $timezone,
        public ?int $staffId = null,
    ) {
        if ($untilDate->lessThan($fromDate)) {
            throw new InvalidArgumentException('The end of the range must not precede its start.');
        }

        $maxDays = (int) config('slotflow.availability.max_range_days', 31);

        if ($fromDate->diffInDays($untilDate) > $maxDays) {
            throw new InvalidArgumentException("An availability range may not exceed {$maxDays} days.");
        }
    }

    /**
     * Build from validated request input.
     *
     * @param  array{date?: string, from?: string, until?: string, tz?: string, timezone?: string, staff_id?: int|string|null}  $input
     */
    public static function fromRequest(Service $service, array $input): self
    {
        $timezone = $input['tz'] ?? $input['timezone'] ?? 'UTC';

        $from = CarbonImmutable::parse($input['from'] ?? $input['date'] ?? 'today', $timezone)->startOfDay();
        $until = isset($input['until'])
            ? CarbonImmutable::parse($input['until'], $timezone)->startOfDay()
            : $from;

        return new self(
            service: $service,
            fromDate: $from,
            untilDate: $until,
            timezone: $timezone,
            staffId: isset($input['staff_id']) && $input['staff_id'] !== ''
                ? (int) $input['staff_id']
                : null,
        );
    }

    /**
     * The search window as an absolute UTC interval.
     */
    public function window(): TimeRange
    {
        return new TimeRange(
            $this->fromDate->setTimezone($this->timezone)->startOfDay()->utc(),
            $this->untilDate->setTimezone($this->timezone)->endOfDay()->utc(),
        );
    }

    public function cacheFragment(): string
    {
        return implode(':', [
            $this->service->id,
            $this->fromDate->toDateString(),
            $this->untilDate->toDateString(),
            $this->timezone,
            $this->staffId ?? 'any',
        ]);
    }
}
