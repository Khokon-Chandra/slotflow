<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /**
     * Statuses that occupy a slot in the diary.
     *
     * Cancelled and no-show bookings keep their row — they are the training
     * data for risk scoring — but they release the time.
     *
     * @return list<string>
     */
    public static function blocking(): array
    {
        return [
            self::Pending->value,
            self::Confirmed->value,
            self::Completed->value,
        ];
    }

    public function blocksSlot(): bool
    {
        return in_array($this->value, self::blocking(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::NoShow], true);
    }

    /**
     * Allowed forward transitions. Enforced in App\Domain\Booking\BookingService
     * so an admin cannot un-cancel a booking whose slot was already resold.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::NoShow, self::Completed],
            self::Confirmed => [self::Completed, self::Cancelled, self::NoShow],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
