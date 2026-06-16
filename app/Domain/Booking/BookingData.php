<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Enums\BookingSource;
use Carbon\CarbonImmutable;

/**
 * Validated input for creating a booking.
 *
 * A DTO rather than a bare array so the controller, the AI assistant and the
 * seeder all reach BookingService through the same, typed door.
 */
final readonly class BookingData
{
    public function __construct(
        public int $serviceId,
        public int $staffId,
        public CarbonImmutable $startsAt,
        public string $customerName,
        public string $customerEmail,
        public ?string $customerPhone = null,
        public string $customerTimezone = 'UTC',
        public ?string $notes = null,
        public BookingSource $source = BookingSource::Web,
        public ?int $customerId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, BookingSource $source = BookingSource::Web): self
    {
        $timezone = (string) ($input['customer_timezone'] ?? $input['tz'] ?? 'UTC');

        return new self(
            serviceId: (int) $input['service_id'],
            staffId: (int) $input['staff_id'],
            // The wire format is an ISO-8601 instant. Parsing it and forcing
            // UTC means a client that sends "+02:00" and a client that sends
            // "Z" describe the same moment, and both are stored identically.
            startsAt: CarbonImmutable::parse((string) $input['starts_at'])->utc(),
            customerName: (string) $input['customer_name'],
            customerEmail: (string) $input['customer_email'],
            customerPhone: isset($input['customer_phone']) ? (string) $input['customer_phone'] : null,
            customerTimezone: $timezone,
            notes: isset($input['notes']) ? (string) $input['notes'] : null,
            source: $source,
            customerId: isset($input['customer_id']) ? (int) $input['customer_id'] : null,
        );
    }
}
