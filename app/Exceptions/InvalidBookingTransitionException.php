<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingStatus;

final class InvalidBookingTransitionException extends DomainException
{
    public function __construct(
        private readonly BookingStatus $from,
        private readonly BookingStatus $to,
    ) {
        parent::__construct(
            "A {$from->value} booking cannot become {$to->value}."
        );
    }

    public function errorCode(): string
    {
        return 'invalid_booking_transition';
    }

    public function context(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'allowed' => array_map(
                fn (BookingStatus $status) => $status->value,
                $this->from->allowedTransitions(),
            ),
        ];
    }
}
