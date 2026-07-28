<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTask: string
{
    case BookingIntent = 'booking_intent';
    case NoShowRationale = 'no_show_rationale';
    case DailyBriefing = 'daily_briefing';
    case ServiceCopy = 'service_copy';

    public function label(): string
    {
        return match ($this) {
            self::BookingIntent => 'Booking request parsing',
            self::NoShowRationale => 'No-show rationale',
            self::DailyBriefing => 'Daily briefing',
            self::ServiceCopy => 'Service description',
        };
    }

    public function rateLimitPerMinute(): int
    {
        /** @var array<string, int> $limits */
        $limits = config('ai.rate_limits', []);

        return $limits[$this->value] ?? 10;
    }
}
