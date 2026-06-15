<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonImmutable;

/**
 * Raised when the requested slot was taken between the customer seeing it and
 * confirming it. On a busy diary this is a normal Tuesday, not an error.
 *
 * 409 rather than 422: the request was well-formed, the world moved.
 */
final class SlotUnavailableException extends DomainException
{
    public function __construct(
        private readonly CarbonImmutable $startsAt,
        private readonly int $staffId,
        string $message = 'That slot has just been taken. Please pick another time.',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'slot_unavailable';
    }

    public function status(): int
    {
        return 409;
    }

    public function context(): array
    {
        return [
            'requested_start' => $this->startsAt->toIso8601String(),
            'staff_id' => $this->staffId,
        ];
    }
}
