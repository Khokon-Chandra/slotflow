<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The requested time is outside what the business allows: in the past, inside
 * the minimum-notice period, or beyond the advance-booking horizon.
 */
final class BookingWindowException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        // Not `$code`: Exception already declares a non-readonly `$code`, and
        // redeclaring it readonly is a fatal error at class-load time.
        private readonly string $errorCode = 'outside_booking_window',
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function tooSoon(int $minutes): self
    {
        return new self(
            "Bookings need at least {$minutes} minutes' notice.",
            'insufficient_notice',
            ['min_notice_minutes' => $minutes],
        );
    }

    public static function tooFarAhead(int $days): self
    {
        return new self(
            "Bookings can be made up to {$days} days in advance.",
            'beyond_booking_horizon',
            ['max_advance_days' => $days],
        );
    }

    public static function serviceUnavailable(): self
    {
        return new self(
            'That service is not currently bookable.',
            'service_inactive',
        );
    }

    public static function staffCannotPerformService(): self
    {
        return new self(
            'That team member does not offer this service.',
            'staff_service_mismatch',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
