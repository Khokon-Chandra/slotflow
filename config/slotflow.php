<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Booking rules
    |--------------------------------------------------------------------------
    |
    | These are platform-wide defaults. A tenant can override most of them in
    | its own `settings` JSON column — see App\Models\Tenant::setting().
    |
    */

    'booking' => [
        // How far ahead a customer may book, in days.
        'max_advance_days' => 60,

        // Minimum notice before a slot starts, in minutes. Stops someone
        // booking the 09:00 slot at 08:59.
        'min_notice_minutes' => 120,

        // Granularity of generated slots, in minutes. A 45 minute service on a
        // 15 minute grid yields 09:00, 09:15, 09:30 … not 09:00, 09:45.
        'slot_granularity_minutes' => 15,

        // Cancellation window before start, in hours. Inside it the customer
        // must contact the business instead of self-cancelling.
        'cancellation_window_hours' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Availability engine
    |--------------------------------------------------------------------------
    */

    'availability' => [
        // Hard ceiling on the number of days one /availability call may span.
        'max_range_days' => 31,

        // Cache computed slots for this many seconds. Invalidated on every
        // booking, rule change and time-off change for the staff member.
        'cache_ttl' => 60,
    ],
];
